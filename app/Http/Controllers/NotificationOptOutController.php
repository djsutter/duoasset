<?php

namespace App\Http\Controllers;

use App\Models\NotificationOptOut;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class NotificationOptOutController extends Controller
{
    /**
     * Display the notification opt-out confirmation page.
     */
    public function show(Request $request): View
    {
        $email = strtolower(trim((string) $request->query('email', $request->input('email', ''))));
        $type = (string) $request->query('type', $request->input('type', ''));
        $key = (string) $request->query('key', $request->input('key', ''));

        if (! $email || ! $type || ! $key) {
            return view('notifications.opt-out', [
                'valid' => false,
                'error' => 'The opt-out link is missing required parameters.',
                'optOut' => null,
                'isOptedOut' => false,
            ]);
        }

        $optOut = NotificationOptOut::where('email', $email)
            ->where('item_type', $type)
            ->where('token', $key)
            ->first();

        if (! $optOut) {
            return view('notifications.opt-out', [
                'valid' => false,
                'error' => 'Invalid or expired opt-out link.',
                'optOut' => null,
                'isOptedOut' => false,
            ]);
        }

        return view('notifications.opt-out', [
            'valid' => true,
            'error' => null,
            'optOut' => $optOut,
            'isOptedOut' => $optOut->opted_out_at !== null,
        ]);
    }

    /**
     * Update the opt-out preference.
     */
    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'type' => ['required', 'string'],
            'key' => ['required', 'string'],
            'action' => ['required', 'string', 'in:opt_out,opt_in'],
        ]);

        $email = strtolower(trim($validated['email']));
        $type = $validated['type'];
        $key = $validated['key'];

        $optOut = NotificationOptOut::where('email', $email)
            ->where('item_type', $type)
            ->where('token', $key)
            ->first();

        if (! $optOut) {
            return response()->view('notifications.opt-out', [
                'valid' => false,
                'error' => 'The opt-out link is invalid or has expired.',
                'optOut' => null,
                'isOptedOut' => false,
            ], 404);
        }

        if ($validated['action'] === 'opt_out') {
            $optOut->optOut();
            $status = "You have been successfully unsubscribed from {$optOut->typeLabel()}.";
        } else {
            $optOut->optIn();
            $status = "You have been successfully resubscribed to {$optOut->typeLabel()}.";
        }

        return redirect()->route('notifications.opt-out', [
            'email' => $optOut->email,
            'type' => $optOut->item_type,
            'key' => $optOut->token,
        ])->with('status', $status);
    }
}
