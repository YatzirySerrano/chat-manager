<?php

namespace App\Services;

use App\Enums\QuickReplyVariable;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestOutcomeEvidence;

/**
 * Turns a quick reply body into the plain text inserted in the composer.
 *
 * Only the placeholders of the fixed catalog (QuickReplyVariable) are
 * replaced, in a single pass and by plain strings: nothing is evaluated and a
 * value containing braces is never interpreted again. Anything else between
 * braces is left untouched (validation rejects it when the reply is saved).
 * A variable without a value becomes an empty string, the spaces around it
 * are tidied up and the variable is reported as missing so the agent can
 * review the text before sending it.
 *
 * Inserted in a conversation, a reply is also completed with the request
 * data the team took from its messages («Datos de la solicitud»): through
 * the «solicitud.*» variables and through its «Etiqueta:» lines left empty
 * («Producto:», «Monto solicitado: $»), see fillRequestLines(). The result
 * is always inserted in the composer for review, never sent on its own.
 */
class QuickReplyRenderer
{
    /**
     * Placeholder of a missing value while the text is being tidied up.
     */
    private const GAP = "\u{E000}";

    /**
     * Placeholder of the single space that may replace a gap.
     */
    private const SPACE = "\u{E001}";

    public function __construct(private PlatformSettings $settings) {}

    /**
     * @param  array<string, string|null>  $context  Values keyed by variable (e.g. "contacto.nombre").
     * @return array{text: string, missing: list<string>}
     */
    public function render(QuickReply|string $body, array $context = []): array
    {
        $text = str_replace([self::GAP, self::SPACE], '', $body instanceof QuickReply ? $body->body : $body);
        $missing = [];

        $rendered = (string) preg_replace_callback('/\{([^{}\r\n]*)\}/u', function (array $match) use ($context, &$missing): string {
            $variable = QuickReplyVariable::tryFrom($match[1]);

            if ($variable === null) {
                return $match[0];
            }

            $value = $this->clean($context[$variable->value] ?? null);

            if ($value === '') {
                $missing[$variable->value] = true;

                return self::GAP;
            }

            return $value;
        }, $text);

        if ($missing !== []) {
            $rendered = $this->closeGaps($rendered);
        }

        return [
            'text' => $rendered,
            'missing' => array_keys($missing),
        ];
    }

    /**
     * Values available to an agent replying, optionally about a contact and
     * within a department.
     *
     * @return array<string, string|null>
     */
    public function context(?User $agent = null, ?Contact $contact = null, ?Department $department = null, ?Conversation $conversation = null): array
    {
        $context = [
            QuickReplyVariable::ContactName->value => $contact?->name,
            QuickReplyVariable::AgentName->value => $agent?->name,
            QuickReplyVariable::DepartmentName->value => $department?->name,
            QuickReplyVariable::CompanyName->value => (string) $this->settings->get('general.platform_name', ''),
        ];

        $values = $conversation === null ? [] : RequestFieldCatalog::values($conversation->request_fields);

        foreach (QuickReplyVariable::cases() as $variable) {
            $key = $variable->requestKey();

            if ($key !== null) {
                $context[$variable->value] = $values[$key] ?? null;
            }
        }

        return $context;
    }

    /**
     * Completes the «Etiqueta:» lines whose value was left empty with the
     * request field of the same label: «Producto:» → «Producto: S12»,
     * «*Cliente:*» → «*Cliente:* Beatriz», «Monto solicitado: $» →
     * «Monto solicitado: $5,000» (never «$$5,000»). Labels are compared
     * normalized and tolerantly (RequestFieldCatalog::keyFor(): accents,
     * case, WhatsApp markers, emojis, typos and truncations do not matter:
     * «Desenbolso: $», «Cant a pagar: $»); a known label left with a bare
     * «$» and no colon («Desembolso $») is completed too. In a reply that
     * announces a result («Se autoriza …») the labels are read as a
     * resolution: a bare «Monto: $» is the «Monto autorizado». Lines that
     * already have a value, hold several labels, or whose label has no
     * captured value stay as written. The values are plain text on one
     * line, never evaluated.
     *
     * @param  array<string, string>  $values  Request field values by key (RequestFieldCatalog::values()).
     * @return array{text: string, filled: list<string>}
     */
    public function fillRequestLines(string $text, array $values): array
    {
        if ($values === []) {
            return ['text' => $text, 'filled' => []];
        }

        $filled = [];
        $context = RequestOutcomeEvidence::announced($text) === null ? RequestFieldCatalog::REQUEST : RequestFieldCatalog::RESOLUTION;

        $fill = function (string $label, string $key, string $dollar) use ($values, &$filled): ?string {
            $value = $this->clean($values[$key] ?? null);

            if ($key === '' || $value === '') {
                return null;
            }

            $filled[$key] = true;

            if ($dollar === '$' || str_starts_with($value, '$')) {
                $value = '$'.ltrim($value, "$ \t");
            }

            return $label.' '.$value;
        };

        $text = (string) preg_replace_callback(
            '/^(?<label>[^:\r\n]{1,60}?[ \t]*:(?:[ \t]*[*_~]+)?)[ \t]*(?<dollar>\$?)[ \t]*$/mu',
            fn (array $match): string => $fill(
                $match['label'],
                RequestFieldCatalog::keyFor(substr($match['label'], 0, (int) strrpos($match['label'], ':')), $context),
                $match['dollar'],
            ) ?? $match[0],
            $text,
        );

        // A known label and a blank amount without the colon: «Desembolso $».
        $text = (string) preg_replace_callback(
            '/^(?<label>[^:$\r\n]{1,60}?)[ \t]+\$[ \t]*$/mu',
            fn (array $match): string => $fill(
                $match['label'],
                RequestFieldCatalog::knownKey(RequestFieldCatalog::normalize($match['label']), $context) ?? '',
                '$',
            ) ?? $match[0],
            $text,
        );

        return ['text' => $text, 'filled' => array_keys($filled)];
    }

    /**
     * A reply rendered for a conversation: its variables (the context comes
     * from context() with the conversation) and then its empty «Etiqueta:»
     * lines, reported in `filled` so the agent knows what to review.
     *
     * @param  array<string, string|null>  $context
     * @return array{text: string, missing: list<string>, filled: list<string>}
     */
    public function renderForConversation(QuickReply|string $body, array $context, Conversation $conversation): array
    {
        $rendered = $this->render($body, $context);
        $completed = $this->fillRequestLines($rendered['text'], RequestFieldCatalog::values($conversation->request_fields));

        return ['text' => $completed['text'], 'missing' => $rendered['missing'], 'filled' => $completed['filled']];
    }

    /**
     * Sample values used by previews.
     *
     * @return array<string, string>
     */
    public function sampleContext(): array
    {
        $context = [];

        foreach (QuickReplyVariable::cases() as $variable) {
            $context[$variable->value] = $variable->example();
        }

        $companyName = trim((string) $this->settings->get('general.platform_name', ''));

        if ($companyName !== '') {
            $context[QuickReplyVariable::CompanyName->value] = $companyName;
        }

        return $context;
    }

    /**
     * Values are inserted as a single line of plain text.
     */
    private function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', str_replace([self::GAP, self::SPACE], '', $value)));
    }

    /**
     * Remove the gaps left by missing values without leaving double spaces,
     * spaces before punctuation or spaces at the edges of a line. Only the
     * whitespace around a gap is touched; the rest of the text is kept as is.
     */
    private function closeGaps(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/[ \t]*'.self::GAP.'(?:[ \t]*'.self::GAP.')*[ \t]*/u',
            fn (array $match): string => preg_match('/[ \t]/', $match[0]) === 1 ? self::SPACE : '',
            $text,
        );

        $text = (string) preg_replace('/'.self::SPACE.'(?=[,.;:!?)\]]|$)/mu', '', $text);
        $text = (string) preg_replace('/(^|[(¿¡\[])'.self::SPACE.'/mu', '$1', $text);

        return str_replace(self::SPACE, ' ', $text);
    }
}
