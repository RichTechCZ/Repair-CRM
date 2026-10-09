<?php
/**
 * Accounting exports (Pohoda XML, S3 Money CSV).
 *
 * Exports are built in memory and returned to the authenticated POST request
 * (accounting_actions.php); nothing is written to temp/, which is web-denied
 * and would otherwise accumulate invoice data on disk.
 */
class AccountingExporter {
    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * @return array{filename:string,mime:string,content:string}
     */
    public function exportToPohoda($id): array {
        $invoice = $this->getFullInvoice($id);
        $ico = (string)get_setting('acc_ico');

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack id="INV' . (int)$invoice['id'] . '" ico="' . $this->xml($ico) . '" application="Service" version="2.0" note="Export faktury"
            xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
            xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
            xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"></dat:dataPack>');

        $item = $xml->addChild('dat:dataPackItem');
        $item->addAttribute('version', '2.0');
        $item->addAttribute('id', (string)$invoice['invoice_number']);

        $inv = $item->addChild('inv:invoice', null, self::NS_INV);
        $inv->addAttribute('version', '2.0');

        $header = $inv->addChild('inv:invoiceHeader');
        $header->addChild(
            'inv:invoiceType',
            ($invoice['invoice_type'] ?? 'invoice') === 'credit_note'
                ? 'issuedCreditNotice'
                : 'issuedInvoice'
        );
        $header->addChild('inv:number', $this->xml($invoice['invoice_number']));
        $header->addChild('inv:date', $this->xml($invoice['date_issue']));
        $header->addChild('inv:dateTax', $this->xml($invoice['date_tax']));
        $header->addChild('inv:dateDue', $this->xml($invoice['date_due']));
        $header->addChild('inv:text', 'Faktura za opravu zařízení');

        // Partner (Customer). addChild() does not escape "&", so every text value goes through xml().
        $customer = $invoice['customer'];
        $partner = $header->addChild('inv:partnerIdentity');
        $address = $partner->addChild('typ:address', null, self::NS_TYP);
        $address->addChild('typ:company', $this->xml($this->partnerName($customer)));
        $address->addChild('typ:city', $this->xml($this->parseCity((string)($customer['address'] ?? ''))));
        $address->addChild('typ:street', $this->xml($this->parseStreet((string)($customer['address'] ?? ''))));
        if (!empty($customer['ico'])) $address->addChild('typ:ico', $this->xml($customer['ico']));
        if (!empty($customer['dic'])) $address->addChild('typ:dic', $this->xml($customer['dic']));

        $header->addChild('inv:paymentType', $this->mapPaymentMethod($invoice['payment_method']));

        $invItems = $inv->addChild('inv:invoiceDetail');
        foreach ($invoice['items'] as $row) {
            $invItem = $invItems->addChild('inv:invoiceItem');
            $invItem->addChild('inv:text', $this->xml($row['item_name']));
            $invItem->addChild('inv:quantity', $this->xml($row['quantity']));
            $invItem->addChild('inv:unit', $this->xml($row['unit']));
            $invItem->addChild('inv:payVat', $invoice['is_vat_payer'] ? 'true' : 'false');
            $invItem->addChild('inv:rateVAT', $this->mapVatRate($row['vat_rate']));

            $homeCurr = $invItem->addChild('inv:homeCurrency');
            $homeCurr->addChild('typ:unitPrice', $this->xml($row['price']), self::NS_TYP);
        }

        return [
            'filename' => 'Pohoda_' . $this->fileToken($invoice['invoice_number']) . '_' . date('YmdHis') . '.xml',
            'mime' => 'application/xml',
            'content' => (string)$xml->asXML(),
        ];
    }

    /**
     * @return array{filename:string,mime:string,content:string}
     */
    public function exportToS3Money($id): array {
        $invoice = $this->getFullInvoice($id);

        $fp = fopen('php://temp', 'w+');
        // Simple S3 Money CSV header
        fputcsv($fp, ['CisloDokladu', 'DatumVystaveni', 'DatumSplatnosti', 'Partner', 'Text', 'Castka', 'DPH']);
        foreach ($invoice['items'] as $item) {
            fputcsv($fp, [
                $invoice['invoice_number'],
                $invoice['date_issue'],
                $invoice['date_due'],
                $this->csvText($this->partnerName($invoice['customer'])),
                $this->csvText((string)$item['item_name']),
                $item['price'] * $item['quantity'],
                $item['vat_rate'],
            ]);
        }
        rewind($fp);
        $content = (string)stream_get_contents($fp);
        fclose($fp);

        return [
            'filename' => 'S3Money_' . $this->fileToken($invoice['invoice_number']) . '_' . date('YmdHis') . '.csv',
            'mime' => 'text/csv',
            'content' => $content,
        ];
    }

    private function getFullInvoice($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException(__('not_found'));
        }

        $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$invoice['customer_id']]);
        $invoice['customer'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
        $stmt->execute([$id]);
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $invoice;
    }

    private function partnerName(array $customer): string {
        $company = trim((string)($customer['company'] ?? ''));
        if ($company !== '') {
            return $company;
        }
        return trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    }

    private function xml($value): string {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Neutralise spreadsheet formula injection in free-text CSV cells. */
    private function csvText(string $value): string {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private function fileToken($invoiceNumber): string {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$invoiceNumber);
    }

    private function mapPaymentMethod($method) {
        switch ($method) {
            case 'bank_transfer': return 'draft';
            case 'cash': return 'cash';
            case 'card': return 'creditcard'; // Pohoda typ:paymentType enum
            case 'cod': return 'delivery'; // dobírka
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
