<?php
/**
 * Shared helpers + CSS for Czech thermal receipts (80 mm).
 * print_thermal.php, print_invoice_thermal.php, print_payroll_thermal.php.
 */
if (!defined('CRM_THERMAL_CLIENT_PHONE')) {
    define('CRM_THERMAL_CLIENT_PHONE', '+420 774 008 600');
}

if (!function_exists('crmThermalSupplierProfile')) {
    /**
     * @return array{name:string,address:string,ico:string,dic:string,is_vat:bool,trade:string}
     */
    function crmThermalSupplierProfile(): array
    {
        $isVat = get_setting('acc_is_vat_payer', '0') === '1';
        return [
            'name' => trim((string)get_setting('acc_company_name', get_setting('company_name', 'Rich Technologies s.r.o.'))),
            'address' => trim((string)get_setting('acc_address', get_setting('company_address', ''))),
            'ico' => trim((string)get_setting('acc_ico', '')),
            'dic' => trim((string)get_setting('acc_dic', '')),
            'is_vat' => $isVat,
            'trade' => trim((string)get_setting('acc_trade_register', '')),
        ];
    }
}

if (!function_exists('crmThermalReceiptCss')) {
    function crmThermalReceiptCss(): void
    {
        echo <<<'CSS'
<style>
    body {
        font-family: Arial, Helvetica, "DejaVu Sans", sans-serif;
        font-size: 12px;
        line-height: 1.25;
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .receipt {
        width: 72mm;
        padding: 2mm;
        margin: 0 auto;
        background: #fff;
    }
    .header {
        text-align: center;
        border-bottom: 2px dashed #000;
        padding-bottom: 6px;
        margin-bottom: 6px;
    }
    .company-name {
        font-size: 14px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 2px;
    }
    .muted { font-size: 11px; }
    .badge-nonvat {
        display: inline-block;
        margin-top: 4px;
        padding: 2px 4px;
        border: 1px solid #000;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .doc-title {
        text-align: center;
        font-size: 13px;
        font-weight: 900;
        margin: 6px 0 2px;
        text-transform: uppercase;
    }
    .doc-number {
        text-align: center;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 6px;
    }
    .section {
        margin-bottom: 6px;
        padding-bottom: 5px;
        border-bottom: 1px dashed #000;
    }
    .row {
        display: flex;
        justify-content: space-between;
        gap: 6px;
        margin-bottom: 2px;
    }
    .label { font-weight: 700; }
    .item { margin-bottom: 5px; }
    .item-name { font-weight: 700; display: block; }
    .item-details {
        display: flex;
        justify-content: space-between;
        gap: 6px;
        font-size: 11px;
    }
    .total-section {
        border-top: 2px solid #000;
        padding-top: 6px;
        margin-top: 6px;
    }
    .total-row {
        display: flex;
        justify-content: space-between;
        font-size: 16px;
        font-weight: 900;
    }
    .legal-note {
        margin-top: 8px;
        font-size: 10px;
        text-align: center;
        font-weight: 700;
        border: 1px solid #000;
        padding: 4px;
    }
    .footer {
        text-align: center;
        margin-top: 10px;
        font-size: 11px;
        border-top: 1px solid #000;
        padding-top: 5px;
    }
    .footer-phone {
        margin-top: 5px;
        font-size: 13px;
        font-weight: 900;
    }
    .barcode {
        text-align: center;
        margin: 8px 0 4px;
        font-size: 14px;
        letter-spacing: 2px;
        font-weight: 700;
    }
    .qr-code { text-align: center; margin: 8px 0 4px; }
    .qr-code img { width: 34mm; height: 34mm; }
    .no-print { text-align: center; margin: 20px; }
    .no-print button, .no-print a {
        display: inline-block;
        margin: 0 6px;
        padding: 10px 20px;
        cursor: pointer;
        border: none;
        border-radius: 4px;
        color: #fff;
        text-decoration: none;
        font-size: 14px;
    }
    .no-print .btn-print { background: #28a745; }
    .no-print .btn-back { background: #6c757d; }
    @media print {
        body { background: none; }
        .receipt { width: 72mm; margin: 0; padding: 0; }
        .no-print { display: none !important; }
        @page { margin: 0; size: 80mm auto; }
    }
</style>
CSS;
    }
}
