<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Storage\Attachments;
use App\Services\Storage\Drivers\WebDav;
use App\Services\Storage\Providers;
use App\Services\Storage\StorageException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files on any record (client, project, contract, quote). Access follows the record's own permissions. */
class AttachmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Attachments::TYPES))],
            'id' => ['required', 'integer'],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file'],
        ], ['files.required' => 'اختر ملفاً واحداً على الأقل.', 'files.*.file' => 'تعذّر استلام الملف؛ ربما تجاوز حد الرفع في الخادم.']);
        $owner = Attachments::resolve($data['type'], (int) $data['id']);
        abort_unless(Attachments::can($request->user(), $owner, 'edit'), 403);

        $ok = 0;
        $failed = [];
        foreach ($request->file('files') as $file) {
            $a = Attachments::store($owner, $file, $request->user());
            if ($a->isUploaded()) {
                $ok++;
            } else {
                $failed[] = $a->name;
            }
        }

        if ($failed) {
            return back()->with('error', 'تعذّر رفع: '.implode('، ', $failed).'. حُفظت نسخة مؤقتة وسيُعاد المحاولة تلقائياً، أو اضغط «إعادة المحاولة».');
        }

        return back()->with('success', $ok === 1 ? 'تم رفع الملف.' : "تم رفع $ok ملفات.");
    }

    /** Open the file in the provider's web app; WebDAV files are streamed through BOS. */
    public function open(Request $request, Attachment $attachment): Response
    {
        $owner = $attachment->attachable;
        abort_unless($owner && Attachments::can($request->user(), $owner, 'view'), 403);
        abort_unless($attachment->isUploaded(), 409, 'الملف لم يُرفع بعد إلى خدمة التخزين.');

        if ($attachment->url) {
            return redirect()->away($attachment->url);
        }
        $c = $attachment->storageConnection;
        $driver = $c ? Providers::driver($c) : null;
        abort_unless($driver instanceof WebDav, 404);

        try {
            $remote = $driver->read($attachment->external_id);
        } catch (StorageException $e) {
            abort(502, $e->getMessage());
        }
        $body = $remote->toPsrResponse()->getBody();

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 256);
                flush();
            }
        }, 200, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename*=UTF-8\'\''.rawurlencode($attachment->name),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function retry(Request $request, Attachment $attachment): RedirectResponse
    {
        $owner = $attachment->attachable;
        abort_unless($owner && Attachments::can($request->user(), $owner, 'edit'), 403);

        return Attachments::attempt($attachment)
            ? back()->with('success', 'تم رفع الملف.')
            : back()->with('error', 'ما زال الرفع يفشل: '.$attachment->error);
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        $owner = $attachment->attachable;
        abort_unless($owner && Attachments::can($request->user(), $owner, 'edit'), 403);
        Attachments::delete($attachment);

        return back()->with('success', 'حُذف الملف.');
    }
}
