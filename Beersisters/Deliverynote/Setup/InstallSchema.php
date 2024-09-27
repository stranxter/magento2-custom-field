<?php
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