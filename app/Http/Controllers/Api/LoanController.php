<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreLoanPaymentRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Resources\LoanPaymentResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\LoanService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(protected LoanService $loans, protected StatisticsService $statistics) {}

    /** GET /loans?range=&status= — totals, every loan and the latest payments. */
    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        return $this->ok($this->loans->overview($user, $range['from'], $range['to']));
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        return $this->created(new LoanResource($this->loans->create($request->user(), $request->validated())), 'Loan added.');
    }

    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);

        return $this->ok(new LoanResource($this->loans->find($request->user(), $loan->id) ?? throw ApiException::notFound()));
    }

    public function update(StoreLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->authorize('update', $loan);

        return $this->ok(new LoanResource($this->loans->update($request->user(), $loan, $request->validated())), 'Loan updated.');
    }

    public function destroy(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('delete', $loan);
        $this->loans->delete($loan);

        return $this->ok(null, 'Loan deleted.');
    }

    /** POST /loans/{loan}/payments */
    public function storePayment(StoreLoanPaymentRequest $request, Loan $loan): JsonResponse
    {
        $this->authorize('update', $loan);
        $payment = $this->loans->addPayment($request->user(), $loan, $request->validated());

        return $this->created(new LoanPaymentResource($payment), $loan->isBorrowed() ? 'Payment recorded.' : 'Repayment received.');
    }

    /** PUT /loan-payments/{payment} */
    public function updatePayment(StoreLoanPaymentRequest $request, LoanPayment $payment): JsonResponse
    {
        $this->authorize('update', $payment);

        return $this->ok(new LoanPaymentResource($this->loans->updatePayment($payment, $request->validated())), 'Payment updated.');
    }

    /** DELETE /loan-payments/{payment} */
    public function destroyPayment(Request $request, LoanPayment $payment): JsonResponse
    {
        $this->authorize('delete', $payment);
        $this->loans->deletePayment($payment);

        return $this->ok(null, 'Payment removed.');
    }
}
