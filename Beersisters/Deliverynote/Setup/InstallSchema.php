<?php
/**
 * SUPERSEDED by etc/db_schema.xml — pending deletion.
 *
 * This legacy script is already inert: module.xml no longer declares a setup_version, so
 * Magento does not run install/upgrade schema scripts for this module at all. It is kept
 * only as a rollback reference until the setup:upgrade --dry-run gate in
 * UPGRADE-PLAN-2.4.9.md §4.2 confirms the declarative schema is a no-op against the
 * migrated database (i.e. it does not drop/recreate columns holding real order notes).
 *
 * Once that gate passes, delete this file.
 */
namespace Beersisters\Deliverynote\Setup;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
   public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
   {
       $installer = $setup;
       $installer->startSetup();

       $installer->getConnection()->addColumn(
           $installer->getTable('quote'),
           'delivery_note',
           [
               'type' => 'text',
               'nullable' => true,
               'comment' => 'Delivery Note',
           ]
       );

       $installer->getConnection()->addColumn(
           $installer->getTable('sales_order'),
           'delivery_note',
           [
               'type' => 'text',
               'nullable' => true,
               'comment' => 'Delivery Note',
           ]
       );

       $installer->getConnection()->addColumn(
           $installer->getTable('sales_order_grid'),
           'delivery_note',
           [
               'type' => 'text',
               'nullable' => true,
               'comment' => 'Delivery Note',
           ]
       );

       $setup->endSetup();
   }
}