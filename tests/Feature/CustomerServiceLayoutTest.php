<?php

namespace Tests\Feature;

use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CustomerServiceResource;
use App\Filament\Resources\Pages\ListCustomerServices;
use App\Filament\Resources\Pages\ListRules;
use App\Models\RuleVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class CustomerServiceLayoutTest extends FeedbackTest
{
    public function test_customer_service_layout_popup_edit_and_create_preserve_closed_feedback_page(): void
    {
        $rules = $this->rule->rules;
        $rules['channels']['always_on'] = false;
        $rules['customer_service']['enabled'] = false;
        $closed = app(Changes::class)->ruleDraft($this->staff, $rules);
        app(Changes::class)->transition($this->staff, 'rules', $closed->id, $closed->revision, 'publish');
        $this->actingAs($this->staff);
        Livewire::test(ListRules::class)->mountTableAction('newVersion', $closed)
            ->assertMountedActionModalDontSee([__('feedback.rules_enable_feedback_page')])
            ->setTableActionData(['channels.auto_prompt' => false])->callMountedTableAction()->assertHasNoActionErrors();
        $edited = RuleVersion::where('status', 'draft')->latest('version')->firstOrFail();
        $this->assertFalse($edited->rules['channels']['always_on']);
        $this->assertFalse($edited->rules['customer_service']['enabled']);
        Livewire::test(ListRules::class)->mountTableAction('draft')
            ->assertMountedActionModalDontSee([__('feedback.rules_enable_feedback_page')])
            ->setTableActionData(['enabled_transaction_types' => ['BUY_GOLD']])->callMountedTableAction()->assertHasNoActionErrors();
        $created = RuleVersion::where('status', 'draft')->latest('version')->firstOrFail();
        $this->assertNotSame($edited->id, $created->id);
        $this->assertFalse($created->rules['channels']['always_on']);
        $this->assertFalse($created->rules['customer_service']['enabled']);
    }

    public function test_customer_service_layout_settings_card_and_single_latest_row_open_a_modal(): void
    {
        $this->actingAs($this->staff);
        $this->get(Settings::getUrl())->assertOk()->assertSee('Customer Service')->assertSee(CustomerServiceResource::getUrl(), false);
        $service = ChannelPolicy::customerService($this->rule->rules);
        $service['name'] = ['en' => 'Latest feedback', 'lo' => 'ຄຳຄິດເຫັນລ່າສຸດ'];
        $draft = app(Changes::class)->ruleDraft($this->staff, array_replace($this->rule->rules, ['customer_service' => $service]));
        $page = Livewire::test(ListCustomerServices::class)->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$this->rule])
            ->assertSee('Latest feedback')->assertSee(__('feedback.customer_service_pending_hint'))
            ->mountTableAction('editDraft', $draft)->assertMountedActionModalSee([__('feedback.customer_service_edit_heading'), __('feedback.customer_service_icon')]);
        $action = $page->instance()->getMountedAction();
        $this->assertNull($action->getUrl());
        $this->assertSame('eg-cs-settings-modal', $action->getExtraModalWindowAttributes()['class']);
        $this->assertSame(['cancel', 'submit'], array_keys($action->getModalFooterActions()));
    }

    public function test_customer_service_layout_upload_is_published_without_changing_popup_or_deleting_previous_images(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff);
        $service = ChannelPolicy::customerService($this->rule->rules);
        $service['enabled'] = false;
        $service['icon_path'] = UploadedFile::fake()->image('service.png', 64, 64);
        Livewire::test(ListCustomerServices::class)->callTableAction('newVersion', $this->rule, data: ['revision' => $this->rule->revision, 'customer_service' => $service])->assertHasNoActionErrors();
        $draft = RuleVersion::where('status', 'draft')->firstOrFail();
        $path = $draft->rules['customer_service']['icon_path'];
        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('/^customer-service-icons\/[A-Za-z0-9_-]+\.png$/', $path);
        $this->assertSame($this->rule->fresh()->rules['transaction_rules'], $draft->rules['transaction_rules']);
        $this->assertSame($this->rule->fresh()->rules['popup_schedule'], $draft->rules['popup_schedule']);
        $this->withHeaders(['Authorization' => 'Bearer test-service-token', 'X-Service-ID' => 'app-backend'])
            ->getJson('/internal/v1/feedback/config?survey_code=experience')->assertOk()
            ->assertJsonPath('app_config.customer_service.icon_url', null)->assertJsonPath('app_config.channels.always_on', true);
        Livewire::test(ListCustomerServices::class)->callTableAction('publish', $draft, data: ['revision' => $draft->revision])->assertHasNoActionErrors();
        $this->getJson('/internal/v1/feedback/config?survey_code=experience')->assertOk()
            ->assertJsonPath('app_config.customer_service.icon_url', asset('storage/'.$path))
            ->assertJsonMissingPath('app_config.customer_service.icon_path')->assertJsonPath('app_config.channels.always_on', false)
            ->assertJsonPath('app_config.channels.auto_prompt', true);
        $published = $draft->fresh();
        $withoutImage = $published->rules['customer_service'];
        $withoutImage['icon_path'] = null;
        Livewire::test(ListCustomerServices::class)->callTableAction('newVersion', $published, data: ['revision' => $published->revision, 'customer_service' => $withoutImage])->assertHasNoActionErrors();
        $removed = RuleVersion::where('status', 'draft')->firstOrFail();
        Livewire::test(ListCustomerServices::class)->callTableAction('publish', $removed, data: ['revision' => $removed->revision])->assertHasNoActionErrors();
        $this->getJson('/internal/v1/feedback/config?survey_code=experience')->assertOk()->assertJsonPath('app_config.customer_service.icon_url', null);
        Storage::disk('public')->assertExists($path);
    }

    public function test_customer_service_layout_permissions_protect_the_page_and_direct_edit_callback(): void
    {
        $reader = User::factory()->create(['active' => true, 'permissions' => ['rules.read']]);
        $this->actingAs($reader)->get(Settings::getUrl())->assertOk();
        $this->get(CustomerServiceResource::getUrl())->assertOk();
        $page = Livewire::test(ListCustomerServices::class)->assertTableActionHidden('newVersion', $this->rule);
        $action = $page->instance()->getTable()->getAction('newVersion')->record($this->rule);
        $this->assertFalse($action->isAuthorized());
        try {
            $action->call(['data' => ['revision' => $this->rule->revision, 'customer_service' => ChannelPolicy::customerService($this->rule->rules)]]);
            $this->fail('Read-only staff changed settings.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
        }
        $this->assertDatabaseCount('rule_versions', 1);
        $reader->update(['permissions' => ['feedback.read']]);
        $this->get(Settings::getUrl())->assertForbidden();
        $this->get(CustomerServiceResource::getUrl())->assertForbidden();
    }

    public function test_customer_service_layout_rejects_external_traversal_missing_and_non_image_uploads(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('customer-service-icons/fake.png', '<?php echo 1;');
        $service = ChannelPolicy::customerService($this->rule->rules);
        foreach (['https://example.test/icon.png', 'customer-service-icons/../../.env', 'customer-service-icons/icon.svg', 'customer-service-icons/missing.png', 'customer-service-icons/fake.png'] as $path) {
            try {
                app(Changes::class)->ruleDraft($this->staff, array_replace($this->rule->rules, ['customer_service' => array_replace($service, ['icon_path' => $path])]));
                $this->fail('Invalid icon accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('customer_service.icon_path', $exception->errors());
            }
        }
        $this->assertDatabaseCount('rule_versions', 1);
    }
}
