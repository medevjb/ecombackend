<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\SaveAddressRequest;
use App\Http\Resources\Api\V1\CustomerAddressResource;
use App\Models\User;
use App\Services\Auth\AddressBook;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AddressController extends Controller
{
    use ApiResponse;

    public function index(Request $request, AddressBook $addresses): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
        $items = $addresses->forCustomer($this->customer($request))->orderByDesc('is_default')->orderBy('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, CustomerAddressResource::collection($items), 'Addresses retrieved.', meta: [
            'pagination' => [
                'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(SaveAddressRequest $request, AddressBook $addresses): JsonResponse
    {
        $address = $addresses->save($this->customer($request), null, $request->validated());

        return $this->apiSuccess($request, new CustomerAddressResource($address), 'Address created.', 201);
    }

    public function update(SaveAddressRequest $request, string $address, AddressBook $addresses): JsonResponse
    {
        $changed = $addresses->save($this->customer($request), $address, $request->validated());

        return $this->apiSuccess($request, new CustomerAddressResource($changed), 'Address updated.');
    }

    public function destroy(Request $request, string $address, AddressBook $addresses): Response
    {
        $addresses->delete($this->customer($request), $address);

        return response()->noContent();
    }

    private function customer(Request $request): User
    {
        $customer = $request->user();
        abort_unless($customer instanceof User, 401);

        return $customer;
    }
}
