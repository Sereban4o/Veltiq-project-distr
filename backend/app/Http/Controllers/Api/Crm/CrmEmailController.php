<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Company;
use App\Models\Crm\Contact;
use App\Models\Crm\Deal;
use App\Models\MailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Exposes the current user's mail linked to a CRM contact, company or deal.
 * Messages are always scoped to the authenticated user for privacy.
 */
class CrmEmailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entity = $this->entity($request);

        $query = MailMessage::query()
            ->where('user_id', Auth::id())
            ->select([
                'id', 'subject', 'from_email', 'from_name', 'preview',
                'received_at', 'sent_at', 'folder', 'is_read', 'has_attachments',
            ])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(100);

        if ($entity instanceof Contact) {
            $query->where('crm_contact_id', $entity->id);
        } elseif ($entity instanceof Company) {
            $query->where('crm_company_id', $entity->id);
        } else {
            $this->scopeForDeal($query, $entity);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function attach(Request $request): JsonResponse
    {
        $entity = $this->entity($request);

        $validated = $request->validate([
            'mail_message_id' => 'required|integer|exists:mail_messages,id',
        ]);

        $message = MailMessage::where('user_id', Auth::id())->findOrFail((int) $validated['mail_message_id']);

        if ($entity instanceof Contact) {
            $message->forceFill(['crm_contact_id' => $entity->id])->saveQuietly();
        } elseif ($entity instanceof Company) {
            $message->forceFill(['crm_company_id' => $entity->id])->saveQuietly();
        } else {
            if ($entity->contact_id === null && $entity->company_id === null) {
                return response()->json(['message' => 'У сделки не указан контакт или компания'], 422);
            }

            $message->forceFill([
                'crm_contact_id' => $entity->contact_id,
                'crm_company_id' => $entity->company_id,
            ])->saveQuietly();
        }

        return $this->index($request);
    }

    public function detach(Request $request): JsonResponse
    {
        $entity = $this->entity($request);

        $mail = MailMessage::where('user_id', Auth::id())->findOrFail((int) $request->route('message'));

        if ($entity instanceof Contact) {
            $mail->forceFill(['crm_contact_id' => null])->saveQuietly();
        } elseif ($entity instanceof Company) {
            $mail->forceFill(['crm_company_id' => null])->saveQuietly();
        } else {
            $updates = [];
            if ($entity->contact_id !== null && (int) $mail->crm_contact_id === (int) $entity->contact_id) {
                $updates['crm_contact_id'] = null;
            }
            if ($entity->company_id !== null && (int) $mail->crm_company_id === (int) $entity->company_id) {
                $updates['crm_company_id'] = null;
            }
            if ($updates !== []) {
                $mail->forceFill($updates)->saveQuietly();
            }
        }

        return $this->index($request);
    }

    private function scopeForDeal(Builder $query, Deal $deal): void
    {
        $query->where(function (Builder $where) use ($deal) {
            $matched = false;

            if ($deal->contact_id !== null) {
                $where->orWhere('crm_contact_id', $deal->contact_id);
                $matched = true;
            }
            if ($deal->company_id !== null) {
                $where->orWhere('crm_company_id', $deal->company_id);
                $matched = true;
            }

            if (! $matched) {
                $where->whereRaw('1 = 0');
            }
        });
    }

    private function entity(Request $request): Contact|Company|Deal
    {
        $type = (string) $request->route('entity');
        $id = (int) $request->route('id');

        return match ($type) {
            'contacts' => Contact::findOrFail($id),
            'companies' => Company::findOrFail($id),
            'deals' => Deal::findOrFail($id),
            default => abort(404),
        };
    }
}
