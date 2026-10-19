<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KbArticle;
use Illuminate\Database\Seeder;

/**
 * Starter help-centre articles, so the help centre (and later the chatbot)
 * has something to work with. Idempotent: keyed by slug, re-running updates
 * the text instead of duplicating rows. Deliberately states no response or
 * resolution times — those come from the SLA settings and change without a
 * deploy.
 */
class KbArticleSeeder extends Seeder
{
    /** @var list<array{slug: string, title: string, body: string}> */
    private const ARTICLES = [
        [
            'slug' => 'how-to-create-a-ticket',
            'title' => 'How to create a support ticket',
            'body' => "Open Tickets in the sidebar and choose New ticket.\n\nGive the ticket a short subject that names the problem (for example \"Invoice PDF won't download\"), then describe what you expected to happen, what happened instead, and the steps that lead to it.\n\nPick the priority that matches the impact on your work and, if you can, a category. You can attach screenshots or documents to help us reproduce the problem.\n\nEvery ticket gets a number such as TKT-2026-000123. Quote it whenever you contact us about the issue.",
        ],
        [
            'slug' => 'ticket-statuses-explained',
            'title' => 'What do the ticket statuses mean?',
            'body' => "Open — we have your ticket and it is waiting for an agent.\nIn progress — an agent is working on it.\nWaiting for customer — we need more information from you. Reply on the ticket to continue.\nResolved — we believe the problem is fixed. If it isn't, reply on the ticket and it will reopen.\nClosed — the ticket is finished. Create a new ticket for a new problem.",
        ],
        [
            'slug' => 'how-priority-works',
            'title' => 'Choosing a priority',
            'body' => "Priority tells us how urgently your problem needs attention.\n\nLow — a question or a minor inconvenience with a workaround.\nMedium — something isn't working, but you can still do your job.\nHigh — an important feature is broken and there is no workaround.\nCritical — the service is down or unusable for many people.\n\nEach priority has a target time for resolution, counted in business hours. An agent may change the priority after reviewing the ticket.",
        ],
        [
            'slug' => 'business-hours',
            'title' => 'When is the support team available?',
            'body' => "Agents work during business hours, which are shown in your ticket's SLA information. Tickets created outside business hours are picked up when the next business day starts, and public holidays are not counted as business time.\n\nYou can create tickets and reply at any time — nothing is lost outside business hours.",
        ],
        [
            'slug' => 'replying-to-a-ticket',
            'title' => 'Replying to a ticket and adding attachments',
            'body' => "Open the ticket and write your reply at the bottom of the conversation. You can attach up to a few files per reply; images, PDFs, text, CSV and Office documents are accepted.\n\nReplying to a resolved ticket reopens it, so the agent sees your message straight away.\n\nPlease don't open a new ticket for the same problem — keeping everything in one ticket keeps the history in one place.",
        ],
        [
            'slug' => 'reset-your-password',
            'title' => 'Reset your password',
            'body' => "On the sign-in page choose Forgot your password? and enter the email address you signed up with. We'll send you a link to choose a new password; the link expires after a short time.\n\nIf no email arrives, check your spam folder. Support staff can never see or tell you your password.",
        ],
        [
            'slug' => 'two-factor-authentication',
            'title' => 'Turn on two-factor authentication',
            'body' => "Go to Settings, then Security, and choose Enable two-factor authentication. Scan the QR code with an authenticator app and enter the code it shows to confirm.\n\nStore the recovery codes somewhere safe: they are the only way in if you lose your phone.",
        ],
        [
            'slug' => 'notifications',
            'title' => 'Email and in-app notifications',
            'body' => "You are notified when an agent replies to your ticket, when its status changes, and when it is resolved. Notifications appear under the bell icon in the app and are also sent by email.\n\nOpen a notification to go straight to the ticket.",
        ],
    ];

    public function run(): void
    {
        foreach (self::ARTICLES as $article) {
            KbArticle::query()->updateOrCreate(
                ['slug' => $article['slug']],
                ['title' => $article['title'], 'body' => $article['body'], 'is_published' => true],
            );
        }
    }
}
