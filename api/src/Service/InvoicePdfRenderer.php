<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Renders an {@see Invoice} to a PDF byte string via a Twig template + dompdf
 * (pure PHP, no wkhtmltopdf binary — fits the slim FrankenPHP image). We own the
 * layout + numbering, so no third-party invoicing product is needed; the PDF is
 * generated on demand and streamed by {@see \App\Controller\InvoicePdfController}.
 *
 * Branding (#669): the space's logo is embedded as a base64 data URI (dompdf
 * runs with remote fetches disabled, so a URL wouldn't load) and the space's
 * default terms print in the footer.
 */
final class InvoicePdfRenderer
{
    public function __construct(
        private Environment $twig,
        #[Autowire(service: 'media.storage')]
        private FilesystemOperator $storage,
    ) {
    }

    public function render(Invoice $invoice): string
    {
        $html = $this->twig->render('invoice/pdf.html.twig', [
            'invoice' => $invoice,
            'logoDataUri' => $this->logoDataUri($invoice),
            'terms' => $invoice->getSpace()?->getInvoiceTerms(),
            'receipts' => $this->receiptImages($invoice),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Image receipts backing this invoice's expense lines (#672), embedded
     * as data URIs (dompdf runs with remote fetches off). PDF-kind receipts
     * are skipped — they can't be inlined into another PDF by dompdf — and
     * unreadable bytes degrade to "no receipt" rather than failing the
     * render.
     *
     * @return list<array{dataUri: string, caption: string}>
     */
    public function receiptImages(Invoice $invoice): array
    {
        $receipts = [];
        foreach ($invoice->getLineItems() as $line) {
            $media = $line->getSourceExpense()?->getReceipt();
            if (null === $media || !str_starts_with($media->getMimeType(), 'image/')) {
                continue;
            }
            $path = $media->getVariantPath('original')
                ?? array_values($media->getVariants())[0]
                ?? null;
            if (null === $path) {
                continue;
            }
            try {
                $bytes = $this->storage->read($path);
            } catch (FilesystemException) {
                continue;
            }
            $receipts[] = [
                'dataUri' => 'data:' . $media->getMimeType() . ';base64,' . base64_encode($bytes),
                'caption' => $line->getDescription(),
            ];
        }

        return $receipts;
    }

    /** A safe download filename for the invoice. */
    public function filename(Invoice $invoice): string
    {
        $number = $invoice->getNumber();
        $base = null !== $number && '' !== $number ? $number : 'invoice-' . $invoice->getId();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $base);

        return ($safe ?? 'invoice') . '.pdf';
    }

    /** The space logo as a data URI, or null (no logo / unreadable bytes). */
    private function logoDataUri(Invoice $invoice): ?string
    {
        $logo = $invoice->getSpace()?->getInvoiceLogo();
        if (null === $logo) {
            return null;
        }
        $path = $logo->getVariantPath('profile')
            ?? $logo->getVariantPath('original')
            ?? array_values($logo->getVariants())[0]
            ?? null;
        if (null === $path) {
            return null;
        }

        try {
            $bytes = $this->storage->read($path);
        } catch (FilesystemException) {
            return null;
        }
        $mime = '' !== $logo->getMimeType() ? $logo->getMimeType() : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
