<?php

namespace App\Services;

use App\Enums\QuickReplyVariable;
use App\Models\Contact;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;

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
    public function context(?User $agent = null, ?Contact $contact = null, ?Department $department = null): array
    {
        return [
            QuickReplyVariable::ContactName->value => $contact?->name,
            QuickReplyVariable::AgentName->value => $agent?->name,
            QuickReplyVariable::DepartmentName->value => $department?->name,
            QuickReplyVariable::CompanyName->value => (string) $this->settings->get('general.platform_name', ''),
        ];
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
