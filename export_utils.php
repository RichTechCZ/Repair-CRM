<?php
class AccountingExporter {
    private $pdo;
    private $exportDir;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->exportDir = 'temp/exports/';
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }
    }

    public function exportToPohoda($id) {
        $invoice = $this->getFullInvoice($id);
        $company_name = get_setting('acc_company_name');
        $ico = get_setting('acc_ico');
        
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack id="INV' . $invoice['id'] . '" ico="' . $ico . '" application="Service" version="2.0" note="Export faktury" 
            xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" 
            xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" 
            xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"></dat:dataPack>');

        $item = $xml->addChild('dat:dataPackItem');
        $item->addAttribute('version', '2.0');
        $item->addAttribute('id', $invoice['invoice_number']);

        $inv = $item->addChild('inv:invoice', null, 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $inv->addAttribute('version', '2.0');

        $header = $inv->addChild('inv:invoiceHeader');
        $header->addChild('inv:invoiceType', 'issuedInvoice');
        $header->addChild('inv:number', $invoice['invoice_number']);
        $header->addChild('inv:date', $invoice['date_issue']);
        $header->addChild('inv:dateTax', $invoice['date_tax']);
        $header->addChild('inv:dateDue', $invoice['date_due']);
        $header->addChild('inv:text', 'Faktura za opravu zařízení');

        // Supplier (My Company)
        // Note: In Pohoda, supplier is often set in the profile, but we can include it
        
        // Partner (Customer)
        $partner = $header->addChild('inv:partnerIdentity');
        $address = $partner->addChild('typ:address', null, 'http://www.stormware.cz/schema/version_2/type.xsd');
        $address->addChild('typ:company', $invoice['customer']['company'] ?: ($invoice['customer']['first_name'] . ' ' . $invoice['customer']['last_name']));
        $address->addChild('typ:city', $this->parseCity($invoice['customer']['address']));
        $address->addChild('typ:street', $this->parseStreet($invoice['customer']['address']));
        if ($invoice['customer']['ico']) $address->addChild('typ:ico', $invoice['customer']['ico']);
        if ($invoice['customer']['dic']) $address->addChild('typ:dic', $invoice['customer']['dic']);

        $header->addChild('inv:paymentType', $this->mapPaymentMethod($invoice['payment_method']));
        
        // Items
        $invItems = $inv->addChild('inv:invoiceDetail');
        foreach ($invoice['items'] as $row) {
            $invItem = $invItems->addChild('inv:invoiceItem');
            $invItem->addChild('inv:text', $row['item_name']);
            $invItem->addChild('inv:quantity', $row['quantity']);
            $invItem->addChild('inv:unit', $row['unit']);
            $invItem->addChild('inv:payVat', $invoice['is_vat_payer'] ? 'true' : 'false');
            $invItem->addChild('inv:rateVAT', $this->mapVatRate($row['vat_rate']));
            
            $homeCurr = $invItem->addChild('inv:homeCurrency');
            $homeCurr->addChild('typ:unitPrice', $row['price'], 'http://www.stormware.cz/schema/version_2/type.xsd');
        }

        $filename = 'Pohoda_' . str_replace('/', '-', $invoice['invoice_number']) . '_' . date('YmdHis') . '.xml';
        $xml->asXML($this->exportDir . $filename);
        return $filename;
    }

    public function exportToS3Money($id) {
        $invoice = $this->getFullInvoice($id);
        $filename = 'S3Money_' . str_replace('/', '-', $invoice['invoice_number']) . '_' . date('YmdHis') . '.csv';
        
        $fp = fopen($this->exportDir . $filename, 'w');
        // Simple S3 Money CSV header
        fputcsv($fp, ['CisloDokladu', 'DatumVystaveni', 'DatumSplatnosti', 'Partner', 'Text', 'Castka', 'DPH']);
        
        foreach ($invoice['items'] as $item) {
            fputcsv($fp, [
                $invoice['invoice_number'],
                $invoice['date_issue'],
                $invoice['date_due'],
                $invoice['customer']['company'] ?: ($invoice['customer']['first_name'] . ' ' . $invoice['customer']['last_name']),
                $item['item_name'],
                $item['price'] * $item['quantity'],
                $item['vat_rate']
            ]);
        }
        
        fclose($fp);
        return $filename;
    }

    private function getFullInvoice($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$invoice['customer_id']]);
        $invoice['customer'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $this->pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$id]);
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $invoice;
    }

    private function mapPaymentMethod($method) {
        switch ($method) {
            case 'bank_transfer': return 'draft';
            case 'cash': return 'cash';
            case 'card': return 'card';
            default: return 'draft';
        }
    }

    private function mapVatRate($rate) {
        if ($rate >= 21) return 'high';
        if ($rate >= 10) return 'low';
        return 'none';
    }

    private function parseCity($address) {
        // Simple heuristic: last line or after comma
        $parts = explode(',', $address);
        return trim(end($parts));
    }

    private function parseStreet($address) {
        $parts = explode(',', $address);
        return trim($parts[0]);
    }
}
