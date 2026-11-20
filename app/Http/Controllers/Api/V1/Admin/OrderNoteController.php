<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Catalog\CatalogMaintenance;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class OrderNoteController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $order, CatalogMaintenance $catalog): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $notes = DB::table('order_notes')->where('order_id', $this->findOrder($order)->id)->latest('id')
            ->paginate($request->integer('per_page', 20), ['public_id as id', 'author_name', 'body', 'created_at'], page: $request->integer('page', 1));

        $notes->through(function (stdClass $note): stdClass {
            $note->created_at = Carbon::parse($note->created_at)->toISOString();

            return $note;
        });

        return $this->apiSuccess($request, $notes->items(), 'Order notes retrieved.', meta: $catalog->meta($notes))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $order, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $actor = $catalog->actor($request);
        $note = [
            'public_id' => (string) Str::ulid(),
            'author_name' => $actor->account->name,
            'body' => $data['body'],
            'created_at' => now(),
        ];
        DB::table('order_notes')->insert($note + ['order_id' => $this->findOrder($order)->id, 'author_id' => $actor->id]);

        return $this->apiSuccess($request, [
            'id' => $note['public_id'],
            'author_name' => $note['author_name'],
            'body' => $note['body'],
            'created_at' => $note['created_at']->toISOString(),
        ], 'Order note added.', 201)->header('Cache-Control', 'no-store');
    }

    private function findOrder(string $order): Order
    {
        return Order::query()->where('public_id', $order)->firstOrFail();
    }
}
