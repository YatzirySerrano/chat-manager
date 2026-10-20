<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ResolveContactReview;
use App\Enums\ContactReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ResolveContactReviewRequest;
use App\Http\Resources\ContactReviewCaseResource;
use App\Models\Contact;
use App\Models\ContactReviewCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactReviewController extends Controller
{
    /**
     * Possible duplicates waiting for a decision (or already decided). Only
     * administrators with organization-wide scope reach this page, so the
     * contacts of every department can be compared here.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('reviewDuplicates', Contact::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ContactReviewStatus::class)],
        ]);
        $status = $filters['status'] ?? ContactReviewStatus::Open->value;

        $cases = ContactReviewCase::query()
            ->where('status', $status)
            ->with(['contact.identities', 'resolver:id,name'])
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $candidates = Contact::query()
            ->with('identities')
            ->whereKey(collect($cases->items())->flatMap(fn (ContactReviewCase $case): array => $case->candidate_contact_ids)->unique()->values()->all())
            ->get()
            ->keyBy('id');

        foreach ($cases->items() as $case) {
            $case->setRelation('candidates', collect($case->candidate_contact_ids)
                ->map(fn (int $id): ?Contact => $candidates->get($id))
                ->filter()
                ->values());
        }

        return Inertia::render('contacts/Reviews', [
            'cases' => ContactReviewCaseResource::collection($cases),
            'filters' => ['status' => $status],
            'statuses' => array_map(fn (ContactReviewStatus $option): array => ['value' => $option->value, 'label' => $option->label()], ContactReviewStatus::cases()),
        ]);
    }

    public function update(ResolveContactReviewRequest $request, ContactReviewCase $contactReviewCase, ResolveContactReview $resolveContactReview): RedirectResponse
    {
        $resolveContactReview->handle($contactReviewCase, $request->resolution(), $request->targetContactId(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Revisión resuelta: '.$request->resolution()->label().'.']);

        return back();
    }
}
