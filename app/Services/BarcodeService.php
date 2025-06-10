<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Illuminate\Support\Facades\File;

class BarcodeService
{
    protected $generator;
    protected $svgGenerator;
    protected $publicPath;

    public function __construct()
    {
        $this->generator = new BarcodeGeneratorPNG();
        $this->svgGenerator = new BarcodeGeneratorSVG();
        $this->publicPath = public_path('barcodes');
        
        // Create barcodes directory if it doesn't exist
        if (!File::exists($this->publicPath)) {
            File::makeDirectory($this->publicPath, 0755, true);
        }
    }

    /**
     * Generate barcode image and return the file path
     */
    public function generateBarcodeImage($barcodeText, $format = 'png')
    {
        try {
            $filename = 'barcode_' . $barcodeText . '_' . time() . '.' . $format;
            $filePath = $this->publicPath . '/' . $filename;
            $relativePath = 'barcodes/' . $filename;

            if ($format === 'svg') {
                // Generate SVG barcode
                $barcodeData = $this->svgGenerator->getBarcode(
                    $barcodeText, 
                    $this->svgGenerator::TYPE_CODE_128,
                    2, // Width factor
                    60 // Height
                );
            } else {
                // Generate PNG barcode
                $barcodeData = $this->generator->getBarcode(
                    $barcodeText, 
                    $this->generator::TYPE_CODE_128,
                    2, // Width factor
                    60 // Height
                );
            }

            // Save the barcode image directly to public directory
            File::put($filePath, $barcodeData);

            return $relativePath;

        } catch (\Exception $e) {
            \Log::error('Error generating barcode image', [
                'barcode' => $barcodeText,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate barcode as base64 data URL
     */
    public function generateBarcodeBase64($barcodeText)
    {
        try {
            $barcodeData = $this->generator->getBarcode(
                $barcodeText, 
                $this->generator::TYPE_CODE_128,
                2, // Width factor
                60 // Height
            );

            return 'data:image/png;base64,' . base64_encode($barcodeData);

        } catch (\Exception $e) {
            \Log::error('Error generating barcode base64', [
                'barcode' => $barcodeText,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate multiple barcode formats
     */
    public function generateBarcodeFormats($barcodeText)
    {
        return [
            'png_path' => $this->generateBarcodeImage($barcodeText, 'png'),
            'svg_path' => $this->generateBarcodeImage($barcodeText, 'svg'),
            'base64' => $this->generateBarcodeBase64($barcodeText)
        ];
    }

    /**
     * Delete barcode files
     */
    public function deleteBarcodeFiles($barcodePaths)
    {
        foreach ($barcodePaths as $path) {
            if ($path) {
                $fullPath = public_path($path);
                if (File::exists($fullPath)) {
                    File::delete($fullPath);
                }
            }
        }
    }

    /**
     * Get full URL for barcode file
     */
    public function getBarcodeUrl($relativePath)
    {
        if ($relativePath) {
            return asset($relativePath);
        }
        return null;
    }
}
