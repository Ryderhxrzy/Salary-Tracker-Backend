<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalaryReceiptRequest;
use App\Http\Resources\SalaryReceiptResource;
use App\Models\SalaryReceipt;
use App\Services\SalaryReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Receive salary": confirm a cut-off's pay arrived (credits the salary wallet) or undo it. */
class SalaryReceiptController extends Controller
{
    public function __construct(protected SalaryReceiptService $receipts) {}

    public function store(StoreSalaryReceiptRequest $request): JsonResponse
    {
        $receipt = $this->receipts->receive($request->user(), $request->validated());

        return $this->created(new SalaryReceiptResource($receipt), 'Salary received.');
    }

    public function destroy(Request $request, SalaryReceipt $receipt): JsonResponse
    {
        $this->authorize('delete', $receipt);
        $this->receipts->delete($receipt);

        return $this->ok(null, 'Salary receipt removed.');
    }
}
