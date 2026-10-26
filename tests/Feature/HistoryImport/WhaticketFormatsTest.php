<?php

use App\Enums\HistoryImportFileKind;
use App\Enums\MessageType;
use App\Services\Migration\History\ChatTextParser;
use App\Services\Migration\History\HistoryColumns;
use App\Services\Migration\History\HistoryValues;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Tolerant readers of the (not officially documented) Whaticket and
| WhatsApp formats, on synthetic fixtures.
*/

test('an Android Chat.txt with system lines, multi-line messages and attachments is read', function () {
    $parsed = app(ChatTextParser::class)->parse((string) file_get_contents(historyFixture('chat-android.txt')), 'America/Mexico_City');
    $messages = array_values(array_filter($parsed['lines'], fn (array $line): bool => ! $line['is_system']));

    expect($parsed['format'])->toBe('chat-txt:android:dmy:24h')
        ->and(count($parsed['lines']) - count($messages))->toBe(1)
        ->and($messages)->toHaveCount(5)
        ->and($messages[0]['occurred_at']->toDateTimeString())->toBe('2026-04-01 15:01:00')
        ->and($messages[1]['body'])->toBe("Buenos días, ¿en qué le ayudamos?\nTenemos horario de 9 a 18.")
        ->and($messages[2]['media_name'])->toBe('img-20260401-wa0001.jpg')
        ->and($messages[2]['type'])->toBe(MessageType::Image)
        ->and($messages[3]['media_name'])->toBeNull()
        ->and($messages[3]['type'])->toBe(MessageType::Image)
        ->and($messages[4]['occurred_at']->toDateTimeString())->toBe('2026-04-14 03:15:00')
        ->and($parsed['participants'])->toBe(['Cliente Ficticio Cinco', 'Empresa Ficticia']);
});

test('an iOS Chat.txt with month-first dates and a 12 hour clock is read', function () {
    $parsed = app(ChatTextParser::class)->parse((string) file_get_contents(historyFixture('chat-ios.txt')), 'America/Mexico_City');

    expect($parsed['format'])->toBe('chat-txt:ios:mdy:12h')
        ->and($parsed['lines'])->toHaveCount(4)
        ->and($parsed['lines'][1]['sender'])->toBe('+52 55 0000 0006')
        ->and($parsed['lines'][1]['occurred_at']->toDateTimeString())->toBe('2026-04-01 20:06:00')
        ->and($parsed['lines'][3]['media_name'])->toBe('00000003-photo-2026-04-14-09-30-00.jpg')
        ->and($parsed['lines'][3]['occurred_at']->toDateTimeString())->toBe('2026-04-14 15:30:00')
        ->and(app(HistoryValues::class)->phone($parsed['lines'][1]['sender']))->toBe('+525500000006');
});

test('a group Chat.txt lists every participant', function () {
    $parsed = app(ChatTextParser::class)->parse((string) file_get_contents(historyFixture('chat-group.txt')), 'UTC');

    expect($parsed['participants'])->toBe(['Empresa Ficticia', 'Participante Ficticio A', 'Participante Ficticio B']);
});

test('dates, numbers and texts of the exports are normalized without inventing data', function () {
    $values = app(HistoryValues::class);

    expect($values->date('2026-03-01 10:00:00', 'America/Mexico_City')?->toDateTimeString())->toBe('2026-03-01 16:00:00')
        ->and($values->date('01/03/2026 10:00', 'America/Mexico_City')?->toDateTimeString())->toBe('2026-03-01 16:00:00')
        ->and($values->date('01/03/26 2:05 p. m.', 'UTC')?->toDateTimeString())->toBe('2026-03-01 14:05:00')
        ->and($values->date('2026-03-01T10:00:00Z', 'America/Mexico_City')?->toDateTimeString())->toBe('2026-03-01 10:00:00')
        ->and($values->date('31/02/2026', 'UTC'))->toBeNull()
        ->and($values->date('mañana', 'UTC'))->toBeNull()
        ->and($values->phone('5215500000001@c.us'))->toBe('+525500000001')
        ->and($values->phone('525500000001'))->toBe('+525500000001')
        ->and($values->phone('120363000000000001@g.us'))->toBeNull()
        ->and($values->body('<p>Hola <b>mundo</b></p>')['text'])->toBe('Hola mundo')
        ->and($values->body("a\x07b")['text'])->toBe('ab')
        ->and($values->type('ptt', 'nota.opus'))->toBe(MessageType::Audio)
        ->and($values->type('chat', null))->toBe(MessageType::Text)
        ->and($values->mediaName('https://example.invalid/public/a%20b.JPG?x=1'))->toBe('a b.jpg');
});

test('the column mapping reports unknown headers and missing required fields', function () {
    $columns = app(HistoryColumns::class);
    $headers = ['ID', 'Ticket ID', 'Mensaje', 'Fecha', 'Columna rara'];
    $mapping = $columns->suggest(HistoryImportFileKind::Messages, $headers);

    expect($mapping['message_id'])->toBe(0)
        ->and($mapping['conversation_id'])->toBe(1)
        ->and($mapping['body'])->toBe(2)
        ->and($mapping['created_at'])->toBe(3)
        ->and($columns->unknownHeaders($mapping, $headers))->toBe(['Columna rara'])
        ->and($columns->missingFields(HistoryImportFileKind::Messages, $mapping))->toBe([])
        ->and($columns->missingFields(HistoryImportFileKind::Messages, [...$mapping, 'created_at' => null]))->toBe(['Fecha y hora'])
        ->and($columns->sanitize(HistoryImportFileKind::Messages, ['body' => 99, 'created_at' => 2, 'message_id' => 2], $headers))->toMatchArray(['message_id' => 2, 'created_at' => null, 'body' => null]);
});
