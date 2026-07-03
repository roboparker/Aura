<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Renders an {@see Invoice} to a PDF byte string via a Twig template + dompdf
 * (pure PHP, no wkhtmltopdf binary — fits the slim FrankenPHP image). We own the
 * layout + numbering, so no third-party invoicing product is needed; the PDF is
 * generated on demand and streamed by {@see \App\Controller\InvoicePdfController}.
 */
final class InvoicePdfRenderer
{
    public function __construct(private Environment $twig)
    {
    }

    public function render(Invoice $invoice): string
    {
        $html = $this->twig->render('invoice/pdf.html.twig', ['invoice' => $invoice]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /** A safe download filename for the invoice. */
    public function filename(Invoice $invoice): string
    {
        $number = $invoice->getNumber();
        $base = null !== $number && '' !== $number ? $number : 'invoice-' . $invoice->getId();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $base);

        return ($safe ?? 'invoice') . '.pdf';
    }
}
