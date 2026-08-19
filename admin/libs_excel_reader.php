<?php
class ExcelReader {
    protected $filePath;
    
    public function __construct($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception('File khong ton tai');
        }
        $this->filePath = $filePath;
    }
    
    public function readRows() {
        $ext = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        
        // Cố gắng detect định dạng thực tế từ nội dung file
        $actualType = $this->detectFileType($this->filePath);
        
        if ($actualType === 'csv') {
            return $this->readCsv();
        } elseif ($actualType === 'xlsx' || $ext === 'xlsx') {
            return $this->readXlsxFlex();
        } elseif ($ext === 'csv') {
            return $this->readCsv();
        }
        
        throw new Exception('Dinh dang khong ho tro: ' . $ext . ' (detect: ' . $actualType . ')');
    }
    
    private function detectFileType($file) {
        $handle = fopen($file, 'r');
        if (!$handle) return 'unknown';
        
        $bytes = fread($handle, 4);
        fclose($handle);
        
        if (strlen($bytes) >= 2) {
            $hex = bin2hex($bytes);
            
            // ZIP file magic: 504b (PK)
            if (substr($hex, 0, 4) === '504b') {
                return 'xlsx';
            }
        }
        
        return 'csv';
    }
    
    private function readCsv() {
        $rows = array();
        $handle = fopen($this->filePath, 'r');
        if (!$handle) throw new Exception('Khong mo duoc CSV');
        $i = 0;
        while (($line = fgetcsv($handle)) !== false) {
            $rows[$i] = array();
            foreach ($line as $j => $val) {
                $rows[$i][$j] = trim($val);
            }
            $i++;
        }
        fclose($handle);
        return $rows;
    }
    
    private function readXlsxFlex() {
        $errors = array();
        
        if (class_exists('ZipArchive')) {
            try {
                return $this->readXlsxRobust();
            } catch (Exception $e) {
                $errors[] = 'ZipArchive: ' . $e->getMessage();
            }
        }
        
        if (in_array('phar', stream_get_wrappers())) {
            try {
                return $this->readXlsxViaPhar();
            } catch (Exception $e) {
                $errors[] = 'Phar: ' . $e->getMessage();
            }
        }
        
        try {
            return $this->convertXlsxToCsv();
        } catch (Exception $e) {
            $errors[] = 'Convert: ' . $e->getMessage();
        }
        
        throw new Exception('Khong the doc XLSX. Hay dung CSV hoac yeu cau hosting bat ZipArchive. Chi tiet: ' . implode('; ', $errors));
    }
    
    private function readXlsxRobust() {
        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== TRUE) {
            throw new Exception('Cannot open as ZIP');
        }
        
        $ss = array();
        $data = @$zip->getFromName('xl/sharedStrings.xml');
        if ($data) {
            $ss = $this->parseStrings($data);
        }
        
        $xml = $this->findFirstSheet($zip);
        $zip->close();
        
        if (!$xml) {
            throw new Exception('No sheets found in file');
        }
        
        return $this->parseSheetXml($xml, $ss);
    }
    
    private function readXlsxViaPhar() {
        $realPath = realpath($this->filePath);
        if (!$realPath) throw new Exception('Cannot resolve path');
        
        $phar = 'phar://' . $realPath . '/';
        
        $ss = array();
        $dataPath = $phar . 'xl/sharedStrings.xml';
        if (@file_exists($dataPath)) {
            $data = @file_get_contents($dataPath);
            if ($data) {
                $ss = $this->parseStrings($data);
            }
        }
        
        $xml = $this->findFirstSheetFromPath($phar);
        if (!$xml) {
            throw new Exception('No sheets found');
        }
        
        return $this->parseSheetXml($xml, $ss);
    }
    
    private function convertXlsxToCsv() {
        $tempDir = sys_get_temp_dir() . '/xlsx_tmp_' . uniqid();
        @mkdir($tempDir, 0777, true);
        
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = '[IO.Compression.ZipFile]::ExtractToDirectory("' . str_replace('"', '\"', $this->filePath) . '", "' . str_replace('"', '\"', $tempDir) . '")';
                $out = array();
                $ret = 0;
                @exec('powershell -NoProfile -Command "' . $cmd . '"', $out, $ret);
                if ($ret !== 0) throw new Exception('PowerShell extract failed');
            } else {
                @exec('unzip -q ' . escapeshellarg($this->filePath) . ' -d ' . escapeshellarg($tempDir), $out, $ret);
                if ($ret !== 0) throw new Exception('unzip failed');
            }
            
            $ss = array();
            $ssPath = $tempDir . '/xl/sharedStrings.xml';
            if (file_exists($ssPath)) {
                $data = file_get_contents($ssPath);
                if ($data) {
                    $ss = $this->parseStrings($data);
                }
            }
            
            $xml = $this->findFirstSheetInDir($tempDir);
            if (!$xml) throw new Exception('No sheets found');
            
            $this->rrmdir($tempDir);
            return $this->parseSheetXml($xml, $ss);
        } catch (Exception $e) {
            $this->rrmdir($tempDir);
            throw $e;
        }
    }
    
    private function findFirstSheet($zip) {
        for ($i = 1; $i <= 20; $i++) {
            $f = 'xl/worksheets/sheet' . $i . '.xml';
            $d = @$zip->getFromName($f);
            if ($d !== false) return $d;
        }
        return null;
    }
    
    private function findFirstSheetFromPath($phar) {
        for ($i = 1; $i <= 20; $i++) {
            $f = $phar . 'xl/worksheets/sheet' . $i . '.xml';
            if (@file_exists($f)) {
                $d = @file_get_contents($f);
                if ($d !== false) return $d;
            }
        }
        return null;
    }
    
    private function findFirstSheetInDir($dir) {
        $sheets = glob($dir . '/xl/worksheets/sheet*.xml');
        if (empty($sheets)) return null;
        return file_get_contents($sheets[0]);
    }
    
    private function parseStrings($xml) {
        $arr = array();
        if (empty($xml)) return $arr;
        
        $x = @simplexml_load_string($xml);
        if (!$x) return $arr;
        
        if (isset($x->si)) {
            foreach ($x->si as $s) {
                $v = '';
                if (isset($s->t)) {
                    $v = (string)$s->t;
                } elseif (isset($s->r)) {
                    foreach ($s->r as $r) {
                        if (isset($r->t)) {
                            $v .= (string)$r->t;
                        }
                    }
                }
                $arr[] = $v;
            }
        }
        
        return $arr;
    }
    
    private function parseSheetXml($xml, $ss) {
        if (empty($xml)) return array();
        
        $rows = array();
        $x = @simplexml_load_string($xml);
        if (!$x) return $rows;
        
        if (!isset($x->sheetData)) return $rows;
        if (!isset($x->sheetData->row)) return $rows;
        
        foreach ($x->sheetData->row as $row) {
            if (!isset($row['r'])) continue;
            
            $rid = (int)$row['r'];
            $data = array();
            
            if (isset($row->c)) {
                foreach ($row->c as $c) {
                    if (!isset($c['r'])) continue;
                    
                    $col = $this->refToCol((string)$c['r']);
                    $type = isset($c['t']) ? (string)$c['t'] : '';
                    $val = '';
                    
                    if ($type === 's' && isset($c->v)) {
                        $idx = (int)$c->v;
                        $val = isset($ss[$idx]) ? $ss[$idx] : '';
                    } elseif (isset($c->v)) {
                        $val = (string)$c->v;
                    }
                    
                    $data[$col] = trim($val);
                }
            }
            
            $rows[$rid] = $data;
        }
        
        if (empty($rows)) return array();
        ksort($rows);
        return array_values($rows);
    }
    
    private function refToCol($ref) {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $col = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $col - 1;
    }
    
    private function rrmdir($dir) {
        if (!file_exists($dir)) return;
        if (is_file($dir)) {
            @unlink($dir);
            return;
        }
        $files = @scandir($dir);
        if (!$files) return;
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

class SimpleXLSXReader extends ExcelReader {}
?>
