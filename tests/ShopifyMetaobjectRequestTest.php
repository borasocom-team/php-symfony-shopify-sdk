<?php
namespace TurboLabIt\ShopifySdk\tests;

use TurboLabIt\ShopifySdk\Request\ShopifyMetaobjectRequest;


/**
 * Live coverage of ensureDefinition()'s field reconciliation against a throwaway definition: it must ADD a newly
 * declared field AND RENAME an existing field whose label drifted, then be a no-op when nothing changed. Each test
 * creates a uniquely-typed definition and deletes it in tearDown, so the store is left clean.
 */
class ShopifyMetaobjectRequestTest extends Base
{
    protected string $serviceId = ShopifyMetaobjectRequest::class;

    private ShopifyMetaobjectRequest $req;
    private string $type;


    protected function setUp() : void
    {
        $this->req  = $this->getInstance();
        $this->type = 'sdktest_recon_' . uniqid();
    }


    protected function tearDown() : void
    {
        try {
            $this->req->deleteDefinition($this->type);
        } catch (\Throwable) {
            // definition never created (or already gone) → nothing to clean up
        }
    }


    public function testReconcileAddsMissingFieldAndRenamesDriftedLabel() : void
    {
        // seed: a definition with a single 'label' field carrying its ORIGINAL name
        $this->req->createDefinition(
            $this->type,
            'SDK Recon Test',
            [['key' => 'label', 'name' => 'Old Label', 'type' => 'single_line_text_field']],
            'label'
        );

        // reconcile: rename 'label' (Old Label → New Label) AND add a brand-new 'extra' field
        $this->req->ensureDefinition(
            $this->type,
            'SDK Recon Test',
            [
                ['key' => 'label', 'name' => 'New Label', 'type' => 'single_line_text_field'],
                ['key' => 'extra', 'name' => 'Extra',     'type' => 'single_line_text_field'],
            ],
            'label'
        );

        // both the renamed and the added key are reported as reconciled
        $arrReconciled = $this->req->getLastReconciledFieldKeys();
        $this->assertContains('label', $arrReconciled, 'renamed field is reported');
        $this->assertContains('extra', $arrReconciled, 'added field is reported');

        // re-read from the STORE (reconcile invalidated the cache): the rename + add must have landed
        $fields = $this->req->getDefinition($this->type)['fields'];
        $this->assertSame('New Label', $fields['label'], 'label was renamed on the store');
        $this->assertSame('Extra',     $fields['extra'], 'extra field was added on the store');

        // idempotency: a second reconcile with the same labels touches nothing
        $this->req->ensureDefinition(
            $this->type,
            'SDK Recon Test',
            [
                ['key' => 'label', 'name' => 'New Label', 'type' => 'single_line_text_field'],
                ['key' => 'extra', 'name' => 'Extra',     'type' => 'single_line_text_field'],
            ],
            'label'
        );
        $this->assertSame([], $this->req->getLastReconciledFieldKeys(), 'no drift → no reconcile');
    }
}
