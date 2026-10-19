<?php

declare(strict_types=1);

use App\Enums\InboundChannel;
use App\Support\Inbound\EmailMessageParser;

test('parses sender, subject, body and Message-ID', function () {
    $data = (new EmailMessageParser)->parse(rawEmail([
        'Message-ID' => '<abc@mail.example.com>',
        'From' => '"Jane Doe" <Jane@Example.com>',
        'Subject' => '=?UTF-8?B?SMOpbGxvIHdvcmxk?=',
    ]));

    expect($data->channel)->toBe(InboundChannel::Email)
        ->and($data->externalMessageId)->toBe('abc@mail.example.com')
        ->and($data->senderAddress)->toBe('Jane@Example.com')
        ->and($data->senderName)->toBe('Jane Doe')
        ->and($data->subject)->toBe('Héllo world')
        ->and($data->body)->toBe('Hello, my order never arrived.')
        ->and($data->receivedAt->toIso8601String())->toBe('2026-10-19T10:00:00+00:00');
});

test('thread keys are In-Reply-To first, then References newest to oldest', function () {
    $data = (new EmailMessageParser)->parse(rawEmail([
        'In-Reply-To' => '<p2@x>',
        'References' => '<p0@x> <p1@x> <p2@x>',
    ]));

    expect($data->threadKeys)->toBe(['p2@x', 'p1@x', 'p0@x']);
});

test('a ticket number in the subject becomes the hint', function () {
    $data = (new EmailMessageParser)->parse(rawEmail(['Subject' => 'Re: [TKT-2026-000042] Order missing']));

    expect($data->ticketNumberHint)->toBe('TKT-2026-000042');
    expect((new EmailMessageParser)->parse(rawEmail(['Subject' => 'TKT-2026-000042 without brackets']))->ticketNumberHint)->toBeNull();
});

test('quoted history under "On … wrote:" is dropped', function () {
    $data = (new EmailMessageParser)->parse(rawEmail(body: "Still broken.\r\n\r\nOn Mon, 19 Oct 2026 at 10:00, Support <support@example.com> wrote:\r\n> Have you tried again?"));

    expect($data->body)->toBe('Still broken.');
});

test('an HTML-only email is turned into plain text', function () {
    $data = (new EmailMessageParser)->parse(rawEmail(html: '<p>Line <b>one</b></p><p>Line &amp; two<script>alert(1)</script></p>'));

    expect($data->body)->toContain('Line one')
        ->and($data->body)->toContain('Line & two')
        ->and($data->body)->not->toContain('<');
});

test('attachments are decoded with their names', function () {
    $data = (new EmailMessageParser)->parse(rawEmail(attachments: ['notes.txt' => 'some notes']));

    expect($data->attachments)->toHaveCount(1)
        ->and($data->attachments[0]->filename)->toBe('notes.txt')
        ->and($data->attachments[0]->contents)->toBe('some notes');
});

test('a message without a Message-ID gets a stable generated id', function () {
    $raw = rawEmail(['Message-ID' => '']);
    $parser = new EmailMessageParser;

    expect($parser->parse($raw)->externalMessageId)->toStartWith('generated-')
        ->and($parser->parse($raw)->externalMessageId)->toBe($parser->parse($raw)->externalMessageId);
});
