<?php

namespace App\Services\Imports;

use App\Http\Requests\Contacts\StoreContactNoteRequest;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use App\Services\Migration\MigrationSourceAdapter;
use App\Support\PhoneNumber;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Illuminate\Support\Str;

/**
 * Turns the rows of an import file into validated contact data: column
 * mapping (with suggestions from the header names), per-row validation with
 * the same rules as the contact form, and duplicate detection inside the
 * file and against the contacts the actor can see.
 *
 * @phpstan-type ImportMapping array<string, int|null>
 * @phpstan-type AnalyzedRow array{
 *     line: int,
 *     status: 'valid'|'duplicate'|'error',
 *     values: array<string, string>,
 *     data: array{name: string, phone_e164: string|null, email: string|null, tag_ids: list<int>, tag_names: list<string>, notes: string|null},
 *     errors: list<string>,
 *     warnings: list<string>,
 *     duplicate_of_line: int|null,
 *     existing: list<array{id: int, name: string}>,
 * }
 */
class ContactImportAnalyzer
{
    /**
     * Importable fields and their labels. Only attributes a contact really
     * has (users, passwords, tokens or messages are never imported).
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'name' => 'Nombre',
        'phone' => 'Teléfono',
        'email' => 'Correo electrónico',
        'tags' => 'Etiquetas',
        'notes' => 'Notas',
    ];

    /**
     * Header names (without accents, lowercase) suggesting each field.
     *
     * @var array<string, list<string>>
     */
    public const HEADER_HINTS = [
        'name' => ['nombre', 'nombre completo', 'nombres', 'name', 'full name', 'contacto', 'cliente', 'razon social'],
        'phone' => ['telefono', 'tel', 'tel.', 'celular', 'cel', 'movil', 'whatsapp', 'numero', 'phone', 'mobile', 'telefono celular', 'numero de telefono'],
        'email' => ['correo', 'correo electronico', 'email', 'e-mail', 'mail', 'correo-e'],
        'tags' => ['etiquetas', 'etiqueta', 'tags', 'tag', 'categorias', 'categoria'],
        'notes' => ['notas', 'nota', 'comentarios', 'comentario', 'observaciones', 'notes', 'descripcion'],
    ];

    public const MAX_NAME = 160;

    public const MAX_PHONE = 32;

    public const MAX_EMAIL = 255;

    public const MAX_TAGS = 20;

    /**
     * Characters that make spreadsheet applications evaluate a cell.
     *
     * @var list<string>
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@'];

    /**
     * Suggested column per field from the header names.
     *
     * @param  list<string>  $headers
     * @return ImportMapping
     */
    public function suggestMapping(array $headers): array
    {
        $mapping = array_fill_keys(array_keys(self::FIELDS), null);
        $taken = [];

        foreach (self::HEADER_HINTS as $field => $hints) {
            foreach ($headers as $index => $header) {
                if (! in_array($index, $taken, true) && in_array($this->normalizeHeader($header), $hints, true)) {
                    $mapping[$field] = $index;
                    $taken[] = $index;

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Problems of a mapping chosen by the user (empty when it is usable).
     *
     * @param  ImportMapping  $mapping
     * @param  list<string>  $headers
     * @return list<string>
     */
    public function mappingErrors(array $mapping, array $headers): array
    {
        $errors = [];
        $used = array_filter($mapping, fn (?int $column): bool => $column !== null);

        foreach ($used as $column) {
            if ($column < 0 || $column >= count($headers)) {
                return ['La asignación de columnas no corresponde al archivo.'];
            }
        }

        if (count($used) !== count(array_unique($used))) {
            $errors[] = 'Cada columna del archivo solo puede asignarse a un campo.';
        }

        if (($mapping['phone'] ?? null) === null && ($mapping['email'] ?? null) === null) {
            $errors[] = 'Asigna la columna del teléfono o la del correo electrónico: cada contacto necesita al menos uno.';
        }

        if (($mapping['name'] ?? null) === null && ($mapping['phone'] ?? null) === null) {
            $errors[] = 'Asigna la columna del nombre o la del teléfono.';
        }

        return $errors;
    }

    /**
     * Validate every row and detect duplicates. The source adapter, when
     * given, normalizes the mapped values first (and may reject rows that
     * are not contacts in that source, such as group chats).
     *
     * @param  list<array{line: int, cells: list<string>}>  $rows
     * @param  ImportMapping  $mapping
     * @param  list<string>  $headers
     * @return list<AnalyzedRow>
     */
    public function analyze(array $rows, array $mapping, User $actor, ?MigrationSourceAdapter $source = null, array $headers = []): array
    {
        $tags = $this->tagIndex();
        $analyzed = [];
        $seenPhones = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $result = $this->analyzeRow($row, $mapping, $tags, $source, $headers);

            if ($result['status'] === 'valid') {
                $phone = $result['data']['phone_e164'];
                $email = $result['data']['email'];
                $previous = ($phone !== null ? ($seenPhones[$phone] ?? null) : null) ?? ($email !== null ? ($seenEmails[$email] ?? null) : null);

                if ($previous !== null) {
                    $result['status'] = 'duplicate';
                    $result['duplicate_of_line'] = $previous;
                } else {
                    if ($phone !== null) {
                        $seenPhones[$phone] = $result['line'];
                    }

                    if ($email !== null) {
                        $seenEmails[$email] = $result['line'];
                    }
                }
            }

            $analyzed[] = $result;
        }

        return $this->markExisting($analyzed, $actor);
    }

    /**
     * Counters of an analysis.
     *
     * @param  list<AnalyzedRow>  $rows
     * @return array{total: int, valid: int, new: int, existing: int, duplicates_in_file: int, errors: int, warnings: int}
     */
    public function summarize(array $rows): array
    {
        $summary = ['total' => count($rows), 'valid' => 0, 'new' => 0, 'existing' => 0, 'duplicates_in_file' => 0, 'errors' => 0, 'warnings' => 0];

        foreach ($rows as $row) {
            match ($row['status']) {
                'valid' => $summary['valid']++,
                'duplicate' => $summary['duplicates_in_file']++,
                'error' => $summary['errors']++,
            };

            if ($row['status'] === 'valid') {
                $row['existing'] === [] ? $summary['new']++ : $summary['existing']++;
            }

            if ($row['warnings'] !== []) {
                $summary['warnings']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array{line: int, cells: list<string>}  $row
     * @param  ImportMapping  $mapping
     * @param  array<string, Tag>  $tags
     * @param  list<string>  $headers
     * @return AnalyzedRow
     */
    private function analyzeRow(array $row, array $mapping, array $tags, ?MigrationSourceAdapter $source, array $headers): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $label) {
            $column = $mapping[$field] ?? null;
            $values[$field] = $column === null ? '' : trim($row['cells'][$column] ?? '');
        }

        $errors = [];
        $warnings = [];

        if ($source !== null) {
            $normalized = $source->normalizeRow($values, $row['cells'], $headers);

            foreach (array_keys(self::FIELDS) as $field) {
                $values[$field] = trim((string) ($normalized['values'][$field] ?? ''));
            }

            array_push($errors, ...$normalized['errors']);
            array_push($warnings, ...$normalized['warnings']);
        }

        $name = $this->singleLine($values['name']);
        $phone = null;
        $email = null;

        if ($values['phone'] !== '') {
            $phone = mb_strlen($values['phone']) > self::MAX_PHONE ? null : PhoneNumber::normalize($values['phone']);

            if ($phone === null) {
                $errors[] = 'Teléfono no válido: escribe 10 dígitos para México o el número completo con lada internacional.';
            }
        }

        if ($values['email'] !== '') {
            $email = mb_strtolower($values['email']);

            if (mb_strlen($email) > self::MAX_EMAIL || ! (new EmailValidator)->isValid($email, new RFCValidation)) {
                $errors[] = 'Correo electrónico no válido.';
                $email = null;
            }
        }

        if ($values['phone'] === '' && $values['email'] === '') {
            $errors[] = 'Falta el teléfono o el correo electrónico: cada contacto necesita al menos uno.';
        }

        if ($name === '' && $phone !== null) {
            $name = $phone;
            $warnings[] = 'Sin nombre: se usará el teléfono como nombre.';
        } elseif ($name === '' && $values['phone'] === '') {
            $errors[] = 'Falta el nombre o el teléfono.';
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            $errors[] = 'El nombre excede '.self::MAX_NAME.' caracteres.';
        }

        [$tagIds, $tagNames, $tagErrors] = $this->resolveTags($values['tags'], $tags);
        array_push($errors, ...$tagErrors);

        $notes = $this->multiLine($values['notes']);

        if (mb_strlen($notes) > StoreContactNoteRequest::MAX_LENGTH) {
            $errors[] = 'La nota excede '.StoreContactNoteRequest::MAX_LENGTH.' caracteres.';
        }

        foreach ($values as $field => $value) {
            // A valid international phone legitimately starts with "+".
            if ($field === 'phone' && $phone !== null) {
                continue;
            }

            if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)) {
                $warnings[] = 'La columna «'.self::FIELDS[$field]."» empieza con «{$value[0]}»: se importará como texto, nunca como fórmula.";
            }
        }

        return [
            'line' => $row['line'],
            'status' => $errors === [] ? 'valid' : 'error',
            'values' => $values,
            'data' => [
                'name' => $name,
                'phone_e164' => $phone,
                'email' => $email,
                'tag_ids' => $tagIds,
                'tag_names' => $tagNames,
                'notes' => $notes === '' ? null : $notes,
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'duplicate_of_line' => null,
            'existing' => [],
        ];
    }

    /**
     * Tags separated by commas, semicolons or "|", matched by name or
     * identifier (case-insensitive). They must exist and not be deleted.
     *
     * @param  array<string, Tag>  $tags
     * @return array{0: list<int>, 1: list<string>, 2: list<string>}
     */
    private function resolveTags(string $value, array $tags): array
    {
        if ($value === '') {
            return [[], [], []];
        }

        $names = array_values(array_unique(array_filter(array_map(
            fn (string $name): string => $this->singleLine($name),
            preg_split('/[,;|]/u', $value) ?: [],
        ), fn (string $name): bool => $name !== '')));

        if (count($names) > self::MAX_TAGS) {
            return [[], [], ['Puedes asignar hasta '.self::MAX_TAGS.' etiquetas por contacto.']];
        }

        $ids = [];
        $labels = [];
        $errors = [];

        foreach ($names as $name) {
            $tag = $tags[mb_strtolower($name)] ?? $tags[Str::slug($name)] ?? null;

            if ($tag === null) {
                $errors[] = "La etiqueta «{$name}» no existe.";
            } elseif ($tag->isDeleted()) {
                $errors[] = "La etiqueta «{$tag->name}» está eliminada y no puede asignarse.";
            } elseif (! in_array($tag->id, $ids, true)) {
                $ids[] = $tag->id;
                $labels[] = $tag->name;
            }
        }

        return [$ids, $labels, $errors];
    }

    /**
     * Every tag indexed by lowercase name and by slug.
     *
     * @return array<string, Tag>
     */
    private function tagIndex(): array
    {
        $index = [];

        foreach (Tag::query()->get(['id', 'name', 'slug', 'is_active']) as $tag) {
            $index[mb_strtolower($tag->name)] = $tag;
            $index[$tag->slug] ??= $tag;
        }

        return $index;
    }

    /**
     * Existing contacts with the same phone or email among the ones the
     * actor can see (same visibility as FindDuplicateContacts), in batches.
     *
     * @param  list<AnalyzedRow>  $rows
     * @return list<AnalyzedRow>
     */
    private function markExisting(array $rows, User $actor): array
    {
        $phones = [];
        $emails = [];

        foreach ($rows as $row) {
            if ($row['status'] === 'valid') {
                if ($row['data']['phone_e164'] !== null) {
                    $phones[] = $row['data']['phone_e164'];
                }

                if ($row['data']['email'] !== null) {
                    $emails[] = $row['data']['email'];
                }
            }
        }

        $byPhone = [];
        $byEmail = [];

        foreach (array_chunk(array_values(array_unique($phones)), 500) as $chunk) {
            foreach (Contact::query()->visibleTo($actor)->hidingDeletedFrom($actor)->whereIn('phone_e164', $chunk)->orderBy('id')->get(['id', 'name', 'phone_e164']) as $contact) {
                $byPhone[(string) $contact->phone_e164][$contact->id] = ['id' => $contact->id, 'name' => $contact->name];
            }
        }

        foreach (array_chunk(array_values(array_unique($emails)), 500) as $chunk) {
            foreach (Contact::query()->visibleTo($actor)->hidingDeletedFrom($actor)->whereIn('email', $chunk)->orderBy('id')->get(['id', 'name', 'email']) as $contact) {
                $byEmail[mb_strtolower((string) $contact->email)][$contact->id] = ['id' => $contact->id, 'name' => $contact->name];
            }
        }

        foreach ($rows as $index => $row) {
            if ($row['status'] !== 'valid') {
                continue;
            }

            $matches = ($byPhone[$row['data']['phone_e164'] ?? ''] ?? []) + ($byEmail[$row['data']['email'] ?? ''] ?? []);
            $rows[$index]['existing'] = array_values($matches);
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($header))), " :*\t");
    }

    /**
     * Plain single-line text: no control characters or markup, collapsed
     * spaces (as the contact form stores names).
     */
    private function singleLine(string $value): string
    {
        return Str::squish(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', strip_tags($value)) ?? '');
    }

    /**
     * Multi-line plain text without control characters (as notes are stored).
     */
    private function multiLine(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", strip_tags($value));

        return trim(preg_replace('/[^\P{Cc}\n\t]+/u', '', $value) ?? '');
    }
}
