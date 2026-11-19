<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffUser;
use App\Models\User;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function catalogStaffToken(string $role): string
{
    $staff = StaffUser::factory()->create();
    $staff->account->assignRole(Role::query()->where('slug', $role)->firstOrFail());

    return $staff->createToken('catalog')->plainTextToken;
}

function checkoutFixture(int $stock = 2): array
{
    $customer = User::factory()->create();
    $address = CustomerAddress::factory()->create(['user_id' => $customer->id, 'line1' => '100 Original Street']);
    $cart = Cart::factory()->create(['user_id' => $customer->id]);
    $product = Product::factory()->create(['status' => ProductStatus::Active, 'regular_amount' => 4200, 'name' => 'Original shirt']);
    $product->categories()->attach(Category::factory()->create());
    $variant = ProductVariant::factory()->for($product)->create();
    $inventory = Inventory::factory()->for($variant, 'variant')->create(['on_hand' => $stock]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);

    return [$customer, $address, $product, $inventory, $cart];
}

function checkoutIntent(string $checkoutId, int $amount, string $status = 'requires_payment_method'): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'id' => 'pi_test_fixture',
        'amount' => $amount,
        'amount_received' => $status === 'succeeded' ? $amount : 0,
        'currency' => 'usd',
        'client_secret' => 'pi_test_fixture_secret_example',
        'livemode' => false,
        'status' => $status,
        'metadata' => ['checkout_id' => $checkoutId],
    ]);
}
