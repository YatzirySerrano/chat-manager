<?php

/*
| Internal (employee) chat. Independent from the customer conversations: its
| messages never reach Meta and never mix with a customer's history.
*/

return [

    /*
    | Text limits. Messages are plain text (multiline); nothing is rendered as
    | HTML.
    */
    'message_max_length' => (int) env('TEAM_CHAT_MESSAGE_MAX_LENGTH', 4000),

    'group_max_participants' => (int) env('TEAM_CHAT_GROUP_MAX_PARTICIPANTS', 100),

    /*
    | Messages loaded per page of the history (cursor pagination).
    */
    'page_size' => 30,

    /*
    | People that can be mentioned (@) in one group message. Only current
    | participants of the group are kept; mentions notify the person even
    | if they muted the group.
    */
    'max_mentions' => 20,

    /*
    | Allowed reactions. Anything else is rejected; reactions notify nobody.
    */
    'reactions' => ['👍', '❤️', '😂', '😮', '😢', '🙏', '✅', '👀'],

    'attachments' => [

        /*
        | Private disk where internal files are stored under a random name
        | (team-chat/{yyyy}/{mm}/{uuid}). It must never be a public disk; any
        | S3-compatible private disk works. Files are only served through the
        | authorized download route.
        */
        'disk' => env('TEAM_CHAT_DISK', 'local'),

        'max_files' => (int) env('TEAM_CHAT_MAX_FILES', 5),

        /*
        | Maximum size of a single file, in kilobytes.
        */
        'max_kilobytes' => (int) env('TEAM_CHAT_MAX_KILOBYTES', 10240),

        /*
        | Allowed real MIME types (detected with finfo from the content, never
        | from the name or the browser) and the extensions each one may have.
        | Executable or scriptable content (HTML, SVG, scripts, binaries) is
        | never accepted.
        */
        'types' => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'text/plain' => ['txt', 'log'],
            'text/csv' => ['csv'],
            'application/msword' => ['doc'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.ms-powerpoint' => ['ppt'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
            'application/vnd.oasis.opendocument.text' => ['odt'],
            'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
            'audio/mpeg' => ['mp3'],
            'audio/ogg' => ['ogg', 'oga'],
            'audio/mp4' => ['m4a'],
            'video/mp4' => ['mp4'],
        ],

        /*
        | Types the browser may show inline (preview). Everything else is
        | always downloaded.
        */
        'inline_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'],
    ],

];
