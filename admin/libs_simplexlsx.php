<?php
/**
 * Thư viện đọc file Excel .xlsx đơn giản, không dùng Composer.
 * 
 * Lưu ý:
 * - Chỉ hỗ trợ các file .xlsx chuẩn (Office / LibreOffice).
 * - Tập trung vào việc đọc dữ liệu dạng bảng từ sheet đầu tiên.
 * - Đủ dùng cho bài toán import sản phẩm, không cố gắng bao phủ toàn bộ spec Excel.
 * 
 * Ý tưởng:
 * - File .xlsx thực chất là file .zip chứa các file XML bên trong.
 * - Dùng ZipArchive để mở file, đọc:
 *   + xl/sharedStrings.xml: chứa danh sách chuỗi dùng chung.
 *   + xl/worksheets/sheet1.xml: dữ liệu sheet đầu tiên.
 * - Parse XML để lấy từng dòng (row) và từng ô (c).
 */

class SimpleXLSXReader
{
    /** @var string Đường dẫn file .xlsx */
    protected $filePath;

    /** @var array|null Danh sách shared strings (chuỗi dùng chung) */
    protected $sharedStrings = null;

    /** @var ZipArchive|null */
    protected $zip = null;

    /**
     * Khởi tạo với đường dẫn file .xlsx
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Mở file .xlsx và chuẩn bị dữ liệu sharedStrings.
     * 
     * @throws Exception Khi file không mở được hoặc không đúng cấu trúc .xlsx
     */
    protected function open()
    {
        if ($this->zip instanceof ZipArchive) {
            return; // đã mở rồi
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception('Máy chủ chưa bật extension ZipArchive, không thể đọc file .xlsx');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            throw new Exception('Không thể mở file .xlsx');
        }

        $this->zip = $zip;

        // Đọc sharedStrings nếu có (dùng để map index -> chuỗi)
        $this->sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml !== false) {
            $xml = @simplexml_load_string($sharedStringsXml);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    // Một shared string có thể là tổ hợp nhiều <t>, ghép lại
                    $value = '';
                    if (isset($si->t)) {
                        $value = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $value .= (string)$run->t;
                        }
                    }
                    $this->sharedStrings[] = $value;
                }
            }
        }
    }

    /**
     * Đọc tất cả các dòng từ sheet đầu tiên (xl/worksheets/sheet1.xml)
     * 
     * @return array Mảng 2 chiều [rowIndex => [colIndex => value]]
     * @throws Exception Nếu sheet1.xml không tồn tại hoặc parse lỗi
     */
    public function readFirstSheetRows()
    {
        $this->open();

        $sheetXml = $this->zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            throw new Exception('Không tìm thấy sheet1 trong file Excel');
        }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml) {
            throw new Exception('Không thể đọc dữ liệu sheet1 trong file Excel');
        }

        $rows = [];
        // Mỗi <row> tương ứng với một dòng
        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int)$row['r']; // số dòng (1-based)
            $rowData = [];

            // Mỗi <c> là một ô
            foreach ($row->c as $c) {
                // Thuộc tính r là địa chỉ ô, ví dụ: A1, B2...
                $cellRef = (string)$c['r'];
                $colIndex = $this->columnIndexFromCellRef($cellRef); // 0-based

                // Kiểu dữ liệu của ô: 's' = shared string, 'str' = string, 'n' = number...
                $type = (string)$c['t'];

                $value = '';
                if ($type === 's') {
                    // Shared string: lấy index, map sang sharedStrings
                    $v = (string)$c->v;
                    $idx = (int)$v;
                    $value = isset($this->sharedStrings[$idx]) ? $this->sharedStrings[$idx] : '';
                } else {
                    // Các kiểu khác: đọc trực tiếp giá trị
                    $value = isset($c->v) ? (string)$c->v : '';
                }

                $rowData[$colIndex] = $value;
            }

            $rows[$rowIndex] = $rowData;
        }

        // Chuẩn hoá: sort theo rowIndex, rồi chuyển về mảng tuần tự 0,1,2,...
        ksort($rows);
        $normalized = [];
        foreach ($rows as $r => $cols) {
            // Bổ sung các cột thiếu bằng chuỗi rỗng (tuỳ chọn, ở đây giữ nguyên sparse array)
            $normalized[] = $cols;
        }

        return $normalized;
    }

    /**
     * Chuyển địa chỉ ô (ví dụ "C5") -> index cột (0-based).
     * 
     * @param string $cellRef
     * @return int
     */
    protected function columnIndexFromCellRef($cellRef)
    {
        // Tách phần chữ (cột) và phần số (dòng), ví dụ "AB12" -> "AB"
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));

        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index *= 26;
            $index += (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1; // chuyển sang 0-based
    }
}


