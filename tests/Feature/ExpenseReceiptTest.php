<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use App\Support\ExpenseReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ExpenseReceipt::DISK);

        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Kabankalan City',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay(),
            'trial_end_date' => now()->addDay(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
        $this->branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $this->branch->id,
            'access' => ['expenses'],
        ]);
    }

    public function test_a_large_receipt_photo_is_stored_as_a_small_jpeg(): void
    {
        $photo = $this->phonePhoto(2400, 1800);
        $this->assertGreaterThan(ExpenseReceipt::MAX_BYTES, $photo->getSize(), 'The test photo should start larger than the cap.');

        $this->actingAs($this->admin)
            ->post(route('admin.expenses.store'), $this->expense(['receipt' => $photo]))
            ->assertSessionHasNoErrors();

        $expense = BranchExpense::query()->firstOrFail();
        $this->assertNotNull($expense->receipt_path);
        Storage::disk(ExpenseReceipt::DISK)->assertExists($expense->receipt_path);

        $stored = Storage::disk(ExpenseReceipt::DISK)->get($expense->receipt_path);
        $this->assertLessThanOrEqual(ExpenseReceipt::MAX_BYTES, strlen($stored));
        [$width, $height, $type] = getimagesizefromstring($stored);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertLessThanOrEqual(ExpenseReceipt::MAX_SIDE, max($width, $height));
        // The photo keeps its shape (4:3).
        $this->assertEqualsWithDelta(4 / 3, $width / $height, 0.01);
    }

    public function test_an_expense_without_a_photo_still_saves(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.expenses.store'), $this->expense())
            ->assertSessionHasNoErrors();

        $this->assertNull(BranchExpense::query()->firstOrFail()->receipt_path);
    }

    public function test_only_photos_are_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.expenses.store'), $this->expense([
                'receipt' => UploadedFile::fake()->create('bill.pdf', 120, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('receipt');

        $this->assertDatabaseCount('branch_expenses', 0);
        $this->assertSame([], Storage::disk(ExpenseReceipt::DISK)->allFiles());
    }

    public function test_receipt_is_only_shown_to_staff_of_that_branch(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.expenses.store'), $this->expense(['receipt' => $this->phonePhoto(800, 600)]))
            ->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.expenses.receipt', $expense))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($this->admin)
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee(route('admin.expenses.receipt', $expense), false)
            ->assertSee('View receipt');

        $otherBranch = Branch::query()->create(['name' => 'Second Branch', 'code' => 'SECOND', 'is_active' => true]);
        $otherManager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $otherBranch->id,
            'access' => ['expenses'],
        ]);
        $this->actingAs($otherManager)
            ->get(route('admin.expenses.receipt', $expense))
            ->assertForbidden();

        auth()->logout();
        $this->get(route('admin.expenses.receipt', $expense))->assertRedirect();
    }

    public function test_editing_can_replace_or_remove_the_photo(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.expenses.store'), $this->expense(['receipt' => $this->phonePhoto(800, 600)]))
            ->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->firstOrFail();
        $firstPhoto = $expense->receipt_path;

        // Editing other fields keeps the photo.
        $this->actingAs($this->admin)
            ->put(route('admin.expenses.update', $expense), $this->expense(['title' => 'Electric bill (September)']))
            ->assertSessionHasNoErrors();
        $this->assertSame($firstPhoto, $expense->fresh()->receipt_path);

        // A new photo replaces the old one, and the old file is deleted.
        $this->actingAs($this->admin)
            ->put(route('admin.expenses.update', $expense), $this->expense(['receipt' => $this->phonePhoto(600, 800)]))
            ->assertSessionHasNoErrors();
        $secondPhoto = $expense->fresh()->receipt_path;
        $this->assertNotSame($firstPhoto, $secondPhoto);
        Storage::disk(ExpenseReceipt::DISK)->assertMissing($firstPhoto);
        Storage::disk(ExpenseReceipt::DISK)->assertExists($secondPhoto);

        // Removing the photo clears it and deletes the file.
        $this->actingAs($this->admin)
            ->put(route('admin.expenses.update', $expense), $this->expense(['remove_receipt' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertNull($expense->fresh()->receipt_path);
        Storage::disk(ExpenseReceipt::DISK)->assertMissing($secondPhoto);
    }

    public function test_deleting_an_expense_deletes_its_photo(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.expenses.store'), $this->expense(['receipt' => $this->phonePhoto(800, 600)]))
            ->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->firstOrFail();
        $photo = $expense->receipt_path;

        $this->actingAs($this->admin)
            ->delete(route('admin.expenses.destroy', $expense))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('branch_expenses', 0);
        Storage::disk(ExpenseReceipt::DISK)->assertMissing($photo);
    }

    private function expense(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'category' => 'utilities',
            'title' => 'Electric bill',
            'amount' => 3200,
            'expense_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'paid_from' => 'store_cash',
        ], $overrides);
    }

    /** A detailed, high-quality JPEG like a phone camera makes (hard to compress). */
    private function phonePhoto(int $width, int $height): UploadedFile
    {
        mt_srand(42);
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'receipt').'.jpg';
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return new UploadedFile($path, 'IMG_2041.jpg', 'image/jpeg', null, true);
    }
}
