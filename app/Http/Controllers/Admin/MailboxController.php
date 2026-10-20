<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MailboxEncryption;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MailboxSettingsRequest;
use App\Http\Resources\MailboxSettingsResource;
use App\Http\Resources\MailboxSyncResource;
use App\Models\MailboxSetting;
use App\Services\MailboxSettingsService;
use App\Services\MailboxSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Mailbox (Step 25b, docs/IMAP.md). */
class MailboxController extends Controller
{
    public function __construct(
        private readonly MailboxSettingsService $settings,
        private readonly MailboxSyncService $syncs,
    ) {}

    public function edit(): Response
    {
        $this->authorize('manage', MailboxSetting::class);

        $settings = $this->settings->current();

        return Inertia::render('admin/mailbox/index', [
            'settings' => new MailboxSettingsResource($settings, $this->settings->passwordSource($settings)),
            'encryptionOptions' => array_map(
                fn (MailboxEncryption $encryption) => ['value' => $encryption->value, 'label' => $encryption->label()],
                MailboxEncryption::cases(),
            ),
            'syncs' => MailboxSyncResource::collection($this->syncs->history()),
        ]);
    }

    public function update(MailboxSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage', MailboxSetting::class);

        $this->settings->update($request->toData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mailbox settings saved.']);

        return redirect()->route('admin.mailbox.edit');
    }

    public function test(MailboxSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage', MailboxSetting::class);

        $error = $this->settings->testConnection($request->toData());

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => 'Connected — the mailbox and folder are reachable.']
            : ['type' => 'error', 'message' => $error]);

        return redirect()->route('admin.mailbox.edit');
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->authorize('manage', MailboxSetting::class);

        $queued = $this->syncs->requestSync($request->user());

        Inertia::flash('toast', $queued
            ? ['type' => 'success', 'message' => 'Sync queued — results appear in the history below.']
            : ['type' => 'error', 'message' => 'Enable and save the mailbox first.']);

        return redirect()->route('admin.mailbox.edit');
    }
}
