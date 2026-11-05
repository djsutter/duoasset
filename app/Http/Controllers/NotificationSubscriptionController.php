<?php

namespace App\Http\Controllers;

use App\Models\NotificationDigestDelivery;
use App\Models\NotificationOptOut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationSubscriptionController extends Controller
{
    private const ALLOWED_CATEGORIES = [
        NotificationDigestDelivery::CATEGORY_EPS_SURPRISE,
        NotificationDigestDelivery::CATEGORY_SETUP_SCORING,
        'both',
    ];

    /**
     * Render the read-only authenticated unsubscribe confirmation screen.
     * Visiting this GET endpoint never mutates user preferences.
     */
    public function confirm(Request $request, string $category): View
    {
        $normalizedCategory = strtolower(trim($category));
        abort_unless(in_array($normalizedCategory, self::ALLOWED_CATEGORIES, true), 404);

        $this->authorizeTargetUser($request);

        $user = $request->user();

        return view('notifications.unsubscribe-confirm', [
            'user' => $user,
            'category' => $normalizedCategory,
            'heading' => $this->headingForCategory($normalizedCategory),
            'description' => $this->descriptionForCategory($normalizedCategory),
        ]);
    }

    /**
     * Execute the state-changing unsubscribe action for the authenticated user.
     */
    public function unsubscribe(Request $request, string $category): RedirectResponse
    {
        $normalizedCategory = strtolower(trim($category));
        abort_unless(in_array($normalizedCategory, self::ALLOWED_CATEGORIES, true), 404);

        $this->authorizeTargetUser($request);

        $user = $request->user();

        if ($normalizedCategory === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE) {
            $user->email_eps_surprise_digest = false;
            $user->notify_eps_earnings = false;
            $user->save();

            NotificationOptOut::getOrCreate($user->email, NotificationOptOut::TYPE_EARNINGS, $user->id)->optOut();
            $message = 'EPS Surprise Notifications have been disabled.';
        } elseif ($normalizedCategory === NotificationDigestDelivery::CATEGORY_SETUP_SCORING) {
            $user->email_setup_scoring_digest = false;
            $user->notify_stock_buy_setup = false;
            $user->save();

            NotificationOptOut::getOrCreate($user->email, NotificationOptOut::TYPE_BUY_SETUP, $user->id)->optOut();
            $message = 'Setup Scoring Notifications have been disabled.';
        } else {
            $user->email_eps_surprise_digest = false;
            $user->notify_eps_earnings = false;
            $user->email_setup_scoring_digest = false;
            $user->notify_stock_buy_setup = false;
            $user->save();

            NotificationOptOut::getOrCreate($user->email, NotificationOptOut::TYPE_EARNINGS, $user->id)->optOut();
            NotificationOptOut::getOrCreate($user->email, NotificationOptOut::TYPE_BUY_SETUP, $user->id)->optOut();
            $message = 'Both EPS Surprise and Setup Scoring digest notifications have been disabled.';
        }

        return redirect()
            ->route('notifications.unsubscribe.confirm', ['category' => $normalizedCategory])
            ->with('status', $message);
    }

    /**
     * Ensure that any user_id or email parameter supplied in the request matches
     * the currently authenticated user. Never trust URL parameters to act on another user.
     */
    private function authorizeTargetUser(Request $request): void
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $requestedUserId = $request->input('user_id', $request->query('user_id'));
        if ($requestedUserId !== null && $requestedUserId !== '' && (int) $requestedUserId !== (int) $user->id) {
            abort(403, 'You may only manage your own notification preferences.');
        }

        $requestedEmail = $request->input('email', $request->query('email'));
        if ($requestedEmail !== null && trim((string) $requestedEmail) !== '') {
            if (strtolower(trim((string) $requestedEmail)) !== strtolower(trim((string) $user->email))) {
                abort(403, 'You may only manage your own notification preferences.');
            }
        }
    }

    private function headingForCategory(string $category): string
    {
        return match ($category) {
            NotificationDigestDelivery::CATEGORY_EPS_SURPRISE => 'Disable EPS Surprise Notifications?',
            NotificationDigestDelivery::CATEGORY_SETUP_SCORING => 'Disable Setup Scoring Notifications?',
            default => 'Disable Both Digest Notifications?',
        };
    }

    private function descriptionForCategory(string $category): string
    {
        return match ($category) {
            NotificationDigestDelivery::CATEGORY_EPS_SURPRISE => 'Confirming will disable personalized EPS Surprise Digest emails for your account. Your Setup Scoring Notification preference will remain unchanged.',
            NotificationDigestDelivery::CATEGORY_SETUP_SCORING => 'Confirming will disable personalized Setup Scoring Digest emails for your account. Your EPS Surprise Notification preference will remain unchanged.',
            default => 'Confirming will disable both EPS Surprise Digest and Setup Scoring Digest emails for your account.',
        };
    }
}
