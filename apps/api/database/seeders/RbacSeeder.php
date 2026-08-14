<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;

/**
 * Seeder RBAC — 16 role dari docs/ERP_GARMENT_ROLES_PERMISSIONS.md (LOCKED v1.1).
 * Permission format: domain.entity.action (BR-110).
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = $this->permissions();

        foreach ($permissions as $code) {
            Permission::firstOrCreate(['code' => $code]);
        }

        foreach ($this->roleMap() as $code => $perms) {
            $role = Role::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => 1, 'code' => $code],
                ['name' => ucwords(str_replace(['_', '-'], ' ', $code))],
            );

            $ids = $perms === ['*']
                ? Permission::pluck('id')
                : Permission::whereIn('code', $perms)->pluck('id');

            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    /** @return array<int, string> */
    private function permissions(): array
    {
        $domains = [
            'core' => ['user' => ['view', 'create', 'update', 'delete'], 'rbac' => ['view', 'manage'], 'settings' => ['view', 'manage'], 'numbering' => ['manage'], 'approval' => ['manage'], 'audit' => ['view']],
            'master' => ['customer' => ['view', 'create', 'update', 'delete'], 'supplier' => ['view', 'create', 'update', 'delete'], 'employee' => ['view', 'create', 'update', 'delete'], 'style' => ['view', 'create', 'update', 'delete'], 'material' => ['view', 'create', 'update', 'delete'], 'uom' => ['view', 'create', 'update', 'delete'], 'warehouse' => ['view', 'create', 'update', 'delete'], 'machine' => ['view', 'create', 'update', 'delete'], 'line' => ['view', 'create', 'update', 'delete'], 'operation' => ['view', 'create', 'update', 'delete'], 'defect' => ['view', 'create', 'update', 'delete'], 'finance' => ['view', 'manage']],
            'sales' => ['order' => ['view', 'create', 'update', 'submit', 'approve', 'cancel'], 'inquiry' => ['view', 'create', 'update']],
            'pd' => ['style' => ['view', 'create', 'update', 'submit'], 'sample' => ['view', 'create', 'update', 'submit'], 'bom' => ['view', 'create', 'update', 'submit', 'approve'], 'routing' => ['view', 'create', 'update', 'submit', 'approve'], 'costing' => ['view', 'create', 'update', 'submit', 'approve'], 'techpack' => ['view', 'create', 'update']],
            'planning' => ['mrp' => ['view', 'execute'], 'production' => ['view', 'create', 'update'], 'cutplan' => ['view', 'create', 'update', 'submit']],
            'purchasing' => ['pr' => ['view', 'create', 'update', 'submit', 'approve'], 'rfq' => ['view', 'create', 'update'], 'po' => ['view', 'create', 'update', 'submit', 'approve', 'cancel'], 'invoice' => ['view', 'create', 'update', 'submit', 'approve']],
            'receiving' => ['gr' => ['view', 'create', 'update', 'submit'], 'inspection' => ['view', 'create', 'update']],
            'inventory' => ['stock' => ['view'], 'reservation' => ['view', 'create'], 'transfer' => ['view', 'create', 'submit'], 'adjustment' => ['view', 'create', 'submit', 'approve'], 'opname' => ['view', 'create', 'submit', 'approve'], 'fabric-roll' => ['view']],
            'production' => ['mo' => ['view', 'create', 'update', 'submit', 'release'], 'issue' => ['view', 'execute'], 'wip' => ['view', 'transfer'], 'output' => ['view', 'create']],
            'cutting' => ['order' => ['view', 'execute'], 'marker' => ['execute'], 'lay' => ['execute'], 'bundle' => ['view', 'execute'], 'leftover' => ['execute']],
            'sewing' => ['output' => ['view', 'create'], 'assignment' => ['view', 'create'], 'downtime' => ['create', 'submit']],
            'finishing' => ['output' => ['view', 'execute']],
            'quality' => ['inspection' => ['view', 'create', 'update', 'submit'], 'defect' => ['view', 'create'], 'ncr' => ['view', 'create', 'update', 'submit'], 'disposition' => ['execute']],
            'packing' => ['instruction' => ['view'], 'packinglist' => ['view', 'create', 'update', 'submit'], 'carton' => ['view', 'create']],
            'shipping' => ['shipment' => ['view', 'create', 'update', 'submit'], 'exportdoc' => ['view', 'create'], 'commercial-invoice' => ['view', 'create', 'submit']],
            'subcon' => ['jwo' => ['view', 'create', 'update', 'submit'], 'movement' => ['view', 'create']],
            'costing' => ['standard' => ['view'], 'actual' => ['view'], 'variance' => ['view'], 'margin' => ['view']],
            'finance' => ['ar-invoice' => ['view', 'create', 'submit'], 'ap' => ['view', 'create'], 'payment' => ['view', 'create', 'submit'], 'journal' => ['view', 'create', 'submit', 'approve'], 'valuation' => ['view'], 'period' => ['lock'], 'period-closing' => ['execute'], 'report' => ['view'], 'bep' => ['view']],
            'reporting' => ['sales' => ['view'], 'ppic' => ['view'], 'inventory' => ['view'], 'purchasing' => ['view'], 'quality' => ['view'], 'finance' => ['view'], 'traceability' => ['view']],
            'dashboard' => ['management' => ['view'], 'ppic' => ['view'], 'warehouse' => ['view'], 'production' => ['view'], 'qc' => ['view']],
        ];

        $list = [];
        foreach ($domains as $domain => $entities) {
            foreach ($entities as $entity => $actions) {
                foreach ($actions as $action) {
                    $list[] = "{$domain}.{$entity}.{$action}";
                }
            }
        }

        return $list;
    }

    /** @return array<string, array<int, string>> */
    private function roleMap(): array
    {
        return [
            'super_admin' => ['*'],
            'admin' => ['core.user.view', 'core.user.create', 'core.user.update', 'core.user.delete', 'core.rbac.view'],
            'sales' => ['master.customer.view', 'master.customer.create', 'master.customer.update', 'sales.order.view', 'sales.order.create', 'sales.order.update', 'sales.order.submit', 'sales.order.cancel', 'sales.inquiry.view', 'sales.inquiry.create', 'sales.inquiry.update', 'pd.style.view', 'pd.costing.view', 'shipping.shipment.view', 'reporting.sales.view'],
            'merchandiser' => ['master.customer.view', 'sales.order.view', 'sales.order.create', 'sales.order.update', 'sales.order.submit', 'sales.inquiry.view', 'pd.style.view', 'pd.sample.view', 'pd.sample.create', 'pd.sample.update', 'pd.sample.submit', 'pd.costing.view', 'pd.costing.create', 'pd.costing.update', 'pd.costing.submit', 'reporting.traceability.view'],
            'product_development' => ['pd.style.view', 'pd.style.create', 'pd.style.update', 'pd.style.submit', 'pd.sample.view', 'pd.sample.create', 'pd.sample.update', 'pd.sample.submit', 'pd.bom.view', 'pd.bom.create', 'pd.bom.update', 'pd.bom.submit', 'pd.routing.view', 'pd.routing.create', 'pd.routing.update', 'pd.routing.submit', 'pd.techpack.view', 'pd.techpack.create', 'pd.techpack.update', 'master.material.view', 'master.operation.view', 'sales.order.view'],
            'ppic' => ['planning.mrp.view', 'planning.mrp.execute', 'planning.production.view', 'planning.production.create', 'planning.production.update', 'planning.cutplan.view', 'planning.cutplan.create', 'planning.cutplan.update', 'planning.cutplan.submit', 'production.mo.view', 'production.mo.create', 'production.mo.update', 'production.mo.submit', 'production.mo.release', 'inventory.stock.view', 'inventory.reservation.view', 'inventory.reservation.create', 'purchasing.pr.create', 'purchasing.pr.update', 'purchasing.pr.submit', 'reporting.ppic.view', 'dashboard.ppic.view'],
            'purchasing' => ['purchasing.pr.view', 'purchasing.rfq.view', 'purchasing.rfq.create', 'purchasing.rfq.update', 'purchasing.po.view', 'purchasing.po.create', 'purchasing.po.update', 'purchasing.po.submit', 'purchasing.po.cancel', 'purchasing.invoice.view', 'purchasing.invoice.create', 'purchasing.invoice.update', 'purchasing.invoice.submit', 'master.supplier.view', 'master.supplier.create', 'master.supplier.update', 'inventory.stock.view', 'planning.mrp.view', 'reporting.purchasing.view'],
            'warehouse' => ['receiving.gr.view', 'receiving.gr.create', 'receiving.gr.update', 'receiving.gr.submit', 'inventory.stock.view', 'inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.submit', 'inventory.adjustment.view', 'inventory.adjustment.create', 'inventory.adjustment.submit', 'inventory.opname.view', 'inventory.opname.create', 'inventory.opname.submit', 'production.issue.execute', 'cutting.leftover.execute', 'inventory.fabric-roll.view', 'reporting.inventory.view', 'dashboard.warehouse.view'],
            'cutting' => ['cutting.order.view', 'cutting.order.execute', 'cutting.marker.execute', 'cutting.lay.execute', 'cutting.bundle.view', 'cutting.bundle.execute', 'inventory.fabric-roll.view', 'production.wip.transfer'],
            'production' => ['sewing.output.view', 'sewing.output.create', 'sewing.assignment.view', 'sewing.assignment.create', 'sewing.downtime.create', 'sewing.downtime.submit', 'finishing.output.view', 'finishing.output.execute', 'production.wip.transfer', 'cutting.bundle.view', 'production.output.create'],
            'qc' => ['quality.inspection.view', 'quality.inspection.create', 'quality.inspection.update', 'quality.inspection.submit', 'quality.defect.view', 'quality.defect.create', 'quality.ncr.view', 'quality.ncr.create', 'quality.ncr.update', 'quality.ncr.submit', 'quality.disposition.execute', 'receiving.inspection.view', 'receiving.inspection.create', 'receiving.inspection.update', 'reporting.quality.view', 'dashboard.qc.view'],
            'packing' => ['packing.instruction.view', 'packing.packinglist.view', 'packing.packinglist.create', 'packing.packinglist.update', 'packing.packinglist.submit', 'packing.carton.view', 'packing.carton.create', 'quality.inspection.view'],
            'shipping' => ['shipping.shipment.view', 'shipping.shipment.create', 'shipping.shipment.update', 'shipping.shipment.submit', 'shipping.exportdoc.view', 'shipping.exportdoc.create', 'packing.packinglist.view', 'sales.order.view', 'finance.ar-invoice.view', 'finance.ar-invoice.create', 'finance.ar-invoice.submit'],
            'finance' => ['finance.ar-invoice.view', 'finance.ap.view', 'finance.ap.create', 'finance.payment.view', 'finance.payment.create', 'finance.payment.submit', 'finance.journal.view', 'finance.journal.create', 'finance.journal.submit', 'finance.valuation.view', 'purchasing.invoice.approve', 'shipping.commercial-invoice.view', 'costing.standard.view', 'costing.actual.view', 'costing.variance.view', 'costing.margin.view', 'reporting.finance.view'],
            'accounting' => ['finance.ar-invoice.view', 'finance.ap.view', 'finance.payment.view', 'finance.journal.view', 'finance.journal.create', 'finance.journal.submit', 'finance.journal.approve', 'finance.valuation.view', 'finance.period.lock', 'finance.period-closing.execute', 'finance.report.view', 'finance.bep.view', 'master.finance.view', 'master.finance.manage', 'costing.standard.view', 'costing.actual.view', 'costing.variance.view', 'costing.margin.view', 'reporting.finance.view'],
            'management' => ['sales.order.view', 'pd.costing.view', 'purchasing.po.view', 'inventory.stock.view', 'production.mo.view', 'quality.inspection.view', 'shipping.shipment.view', 'finance.journal.view', 'finance.report.view', 'finance.bep.view', 'costing.margin.view', 'core.audit.view', 'dashboard.management.view', 'reporting.sales.view', 'reporting.ppic.view', 'reporting.inventory.view', 'reporting.purchasing.view', 'reporting.quality.view', 'reporting.finance.view', 'reporting.traceability.view'],
        ];
    }
}
