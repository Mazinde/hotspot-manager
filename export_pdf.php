<?php
session_start();

// Ensure the PDF library is loaded (assuming composer autoload is available)
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Check if data exists in session
if (!isset($_SESSION['vouchers_to_print']) || empty($_SESSION['vouchers_to_print'])) {
    die('No voucher data found for export. Please generate or select a batch first.');
}

$vouchers = $_SESSION['vouchers_to_print'];
$batchName = $_SESSION['vouchers_batch_name'] ?? 'Voucher Batch';

// Clear the session data immediately after retrieval to prevent re-download
unset($_SESSION['vouchers_to_print']);
unset($_SESSION['vouchers_batch_name']);


// 2. Generate the beautiful HTML/CSS voucher template
$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Vouchers - ' . htmlspecialchars($batchName) . '</title>
    <style>
        @page { margin: 10mm; }
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        
        /* Removed .voucher-grid and its flex properties, relying on float for 2-column layout */
        
        .voucher-card {
            width: 48%; /* Two columns */
            height: 80mm; 
            margin-bottom: 10mm;
            border: 2px solid #2b75d9;
            border-radius: 8px;
            padding: 10mm;
            box-sizing: border-box;
            float: left; /* Essential for side-by-side layout in PDF */
            page-break-inside: avoid; /* Prevents card from splitting over two pages */
        }
        
        /* The clearfix container is critical to ensure the floats behave on every row */
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }

        /* * FIX: Control the flow to start new lines/pages after every second card.
         * The combination of float and a specific break rule ensures pagination stability.
         */
        .voucher-card:nth-child(even) { 
            margin-right: 0; 
            /* Force a page break after every second voucher */
            page-break-after: always; 
        }
        .voucher-card:nth-child(odd) { 
            margin-right: 4%; /* Space between columns */
        }

        .header-section {
            background-color: #2b75d9;
            color: white;
            padding: 5px 10px;
            border-radius: 4px 4px 0 0;
            text-align: center;
            font-size: 14pt;
            /* Adjust margins to position correctly within the card */
            margin: -10mm -10mm 5mm -10mm; 
        }
        h2 { margin-top: 0; font-size: 16pt; color: #1a4fa0; }
        .data {
            font-size: 12pt;
            margin-top: 10px;
            text-align: center;
            line-height: 1.5;
        }
        .data strong {
            display: block;
            font-size: 20pt;
            color: #dc3545;
            margin-top: 5px;
            border: 1px dashed #ccc;
            padding: 5px;
        }
        .footer-note {
            text-align: center;
            font-size: 8pt;
            color: #666;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div style="text-align: center; margin-bottom: 15mm; font-size: 16pt;">
        <h1>Hotspot Vouchers Batch: ' . htmlspecialchars($batchName) . '</h1>
    </div>
    
    <div class="clearfix">
';

foreach ($vouchers as $index => $voucher) {
    $html .= '
        <div class="voucher-card">
            <div class="header-section">HOTSPOT VOUCHER</div>
            
            <div class="data">
                Voucher Profile: ' . htmlspecialchars($voucher['profile']) . '
            </div>
            
            <div class="data">
                Username: <strong>' . htmlspecialchars($voucher['username']) . '</strong>
                Password: <strong>' . htmlspecialchars($voucher['password']) . '</strong>
            </div>

            <div class="footer-note">
                Scan the QR code on the Hotspot Login page.<br>
                For support, contact IT: (123) 456-7890
            </div>
        </div>
    ';
    
    // Explicitly add a page break element after every two vouchers (if not the last one)
    // Note: The CSS page-break-after: always on the 'even' voucher handles this implicitly, 
    // but sometimes an explicit break can improve reliability in some Dompdf versions. 
    // For this 2-column layout, relying on the CSS is cleaner.
}

$html .= '
    </div>
</body>
</html>
';

// 3. Instantiate and generate PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('defaultPaperSize', 'A4');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

// Render the HTML as PDF (landscape orientation works well for two columns)
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// 4. Output the generated PDF to browser
$filename = str_replace(' ', '_', $batchName) . '_Vouchers_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);

exit(0);