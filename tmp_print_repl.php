<?php
$files = glob(__DIR__ . '/print_*.php');
foreach ($files as $file) {
    if (!is_file($file)) continue;
    $content = file_get_contents($file);
    
    $reps = [
        '>FAKTURA - DAŇOVÝ DOKLAD<' => '><?php echo __(\'print_title_invoice\'); ?><',
        '>Faktura ' => '><?php echo __(\'print_title_invoice\'); ?> ',
        '>Účtenka<' => '><?php echo __(\'print_title_receipt\'); ?><',
        '>Servisní zakázka<' => '><?php echo __(\'print_title_order\'); ?><',
        '>Pracovní příkaz<' => '><?php echo __(\'print_title_workshop\'); ?><',
        '>Tisk faktury<' => '><?php echo __(\'print_btn\'); ?><',
        '>Tisk účtenky<' => '><?php echo __(\'print_btn\'); ?><',
        '>Tisk zakázky<' => '><?php echo __(\'print_btn\'); ?><',
        '>Tisk protokolu<' => '><?php echo __(\'print_btn\'); ?><',
        '>Množství<' => '><?php echo __(\'table_quantity\'); ?><',
        '>Jedn. cena<' => '><?php echo __(\'table_price\'); ?><',
        '>Cena<' => '><?php echo __(\'table_price\'); ?><',
        '>Celkem<' => '><?php echo __(\'table_total\'); ?><',
        '>Sazba DPH<' => '><?php echo __(\'table_vat_rate\'); ?><',
        'Faktura slouží zároveň jako dodací a záruční list' => '<?php echo __(\'print_guarantee_note\'); ?>',
        'Faktura slouží zároveň jako záruční list' => '<?php echo __(\'print_guarantee_note\'); ?>',
        '>Dokument nenalezen<' => '><?php echo __(\'print_not_found\'); ?><',
        '>Faktura nenalezena<' => '><?php echo __(\'print_not_found\'); ?><',
        '"Faktura nenalezena"' => '"<?php echo __(\'print_not_found\'); ?>"',
        '"ID faktury není zadáno"' => '"<?php echo __(\'missing_id\'); ?>"',
        'die("Unauthorized access");' => 'die(__("unauthorized"));',
        'die("Faktura nenalezena");' => 'die(__("print_not_found"));',
        'die("Zakázka nenalezena");' => 'die(__("print_not_found"));',
        'die("ID faktury není zadáno");' => 'die(__("missing_id"));',
        'die("ID zakázky chybí");' => 'die(__("missing_id"));',
        '>Zpět<' => '><?php echo __(\'back\'); ?><',
        '<title>Faktura ' => '<title><?php echo __(\'print_title_invoice\'); ?> ',
        '<title>Zakázka ' => '<title><?php echo __(\'print_title_order\'); ?> ',
        '<title>Účtenka ' => '<title><?php echo __(\'print_title_receipt\'); ?> ',
    ];
    
    $content = str_replace(array_keys($reps), array_values($reps), $content);
    file_put_contents($file, $content);
}
echo "Replaced common strings in print files\n";
