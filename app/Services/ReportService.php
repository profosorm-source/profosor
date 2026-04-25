<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;

/**
 * ReportService
 * تولید گزارش‌های مختلف (CSV, Excel, PDF)
 */
class ReportService
{
    /**
     * تولید CSV
     */
    public function generateCSV(array $data): void
    {
        $filename = 'analytics_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        
        $output = fopen('php://output', 'w');
        
        // BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, ['گزارش تحلیلات', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        // User Metrics
        if (!empty($data['users'])) {
            fputcsv($output, ['تحلیلات کاربران']);
            fputcsv($output, ['توضیح', 'مقدار']);
            fputcsv($output, ['کل کاربران', $data['users']['total_users']]);
            fputcsv($output, ['کاربران فعال', $data['users']['active_users']]);
            fputcsv($output, ['کاربران جدید', $data['users']['new_users']]);
            fputcsv($output, ['KYC تأیید شده', $data['users']['kyc_verified']]);
            fputcsv($output, []);
        }
        
        // Transaction Metrics
        if (!empty($data['transactions'])) {
            fputcsv($output, ['تحلیلات تراکنش‌ها']);
            fputcsv($output, ['توضیح', 'تعداد', 'مبلغ']);
            fputcsv($output, ['واریز‌ها', $data['transactions']['deposits']['count'], $data['transactions']['deposits']['amount']]);
            fputcsv($output, ['برداشت‌ها', $data['transactions']['withdrawals']['count'], $data['transactions']['withdrawals']['amount']]);
            fputcsv($output, ['پرداخت‌ها', $data['transactions']['payments']['count'], $data['transactions']['payments']['amount']]);
            fputcsv($output, ['درآمد پلتفرم', '', $data['transactions']['platform_fee']]);
            fputcsv($output, []);
        }
        
        // Social Tasks
        if (!empty($data['social_tasks'])) {
            fputcsv($output, ['وظایف اجتماعی']);
            fputcsv($output, ['توضیح', 'مقدار']);
            fputcsv($output, ['کل آگهی‌ها', $data['social_tasks']['ads']['total']]);
            fputcsv($output, ['کل اجراها', $data['social_tasks']['executions']['total']]);
            fputcsv($output, ['نرخ تایید', $data['social_tasks']['executions']['approval_rate'] . '%']);
            fputcsv($output, []);
        }
        
        // Ratings
        if (!empty($data['ratings'])) {
            fputcsv($output, ['امتیازات']);
            fputcsv($output, ['توضیح', 'مقدار']);
            fputcsv($output, ['کل امتیازات', $data['ratings']['total_ratings']]);
            fputcsv($output, ['میانگین امتیاز', $data['ratings']['average_rating']]);
            fputcsv($output, []);
        }
        
        // Revenue
        if (!empty($data['revenue'])) {
            fputcsv($output, ['درآمد']);
            fputcsv($output, ['توضیح', 'مبلغ']);
            fputcsv($output, ['درآمد کل', $data['revenue']['income']['total']]);
            fputcsv($output, ['هزینه‌ها', $data['revenue']['expenses']['total']]);
            fputcsv($output, ['سود خالص', $data['revenue']['net_profit']]);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تولید Excel (ساده با CSV بهتری)
     */
    public function generateExcel(array $data): void
    {
        // استفاده از کتابخانه PhpSpreadsheet در صورت نیاز
        // اینجا CSV میفرستیم با .xlsx
        $filename = 'analytics_report_' . date('Y-m-d_H-i-s') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        
        // ساده‌ترین روش: HTML table به Excel
        $html = $this->generateExcelHTML($data);
        
        echo $html;
        exit;
    }

    /**
     * تولید PDF
     */
    public function generatePDF(array $data): void
    {
        // در صورت نصب dompdf یا tcpdf
        $filename = 'analytics_report_' . date('Y-m-d_H-i-s') . '.pdf';
        
        // ساده‌ترین روش: HTML و درخواست کاربر برای چاپ
        header('Content-Type: text/html; charset=UTF-8');
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title><?= $filename ?></title>
            <style>
                * { margin: 0; padding: 0; }
                body { font-family: Arial, sans-serif; direction: rtl; background: white; }
                h1 { text-align: center; margin: 20px 0; }
                h2 { margin: 20px 0 10px; border-bottom: 2px solid #333; padding-bottom: 5px; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background: #f2f2f2; font-weight: bold; }
                tr:nth-child(even) { background: #f9f9f9; }
                .header { text-align: center; margin-bottom: 20px; }
                .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                @media print { body { margin: 0; padding: 10px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>گزارش تحلیلات سیستم</h1>
                <p>تاریخ: <?= date('Y-m-d H:i:s') ?></p>
                <p>دوره: <?= $data['period'] ?? 'ماه' ?></p>
            </div>

            <?php if (!empty($data['users'])): ?>
            <h2>تحلیلات کاربران</h2>
            <table>
                <tr>
                    <th>توضیح</th>
                    <th>مقدار</th>
                </tr>
                <tr>
                    <td>کل کاربران</td>
                    <td><?= $data['users']['total_users'] ?></td>
                </tr>
                <tr>
                    <td>کاربران فعال</td>
                    <td><?= $data['users']['active_users'] ?></td>
                </tr>
                <tr>
                    <td>کاربران جدید</td>
                    <td><?= $data['users']['new_users'] ?></td>
                </tr>
                <tr>
                    <td>KYC تأیید شده</td>
                    <td><?= $data['users']['kyc_verified'] ?></td>
                </tr>
            </table>
            <?php endif; ?>

            <?php if (!empty($data['transactions'])): ?>
            <h2>تحلیلات تراکنش‌ها</h2>
            <table>
                <tr>
                    <th>نوع</th>
                    <th>تعداد</th>
                    <th>مبلغ</th>
                </tr>
                <tr>
                    <td>واریز‌ها</td>
                    <td><?= $data['transactions']['deposits']['count'] ?></td>
                    <td><?= number_format((float)$data['transactions']['deposits']['amount'], 0) ?></td>
                </tr>
                <tr>
                    <td>برداشت‌ها</td>
                    <td><?= $data['transactions']['withdrawals']['count'] ?></td>
                    <td><?= number_format((float)$data['transactions']['withdrawals']['amount'], 0) ?></td>
                </tr>
                <tr>
                    <td>پرداخت‌ها</td>
                    <td><?= $data['transactions']['payments']['count'] ?></td>
                    <td><?= number_format((float)$data['transactions']['payments']['amount'], 0) ?></td>
                </tr>
            </table>
            <?php endif; ?>

            <?php if (!empty($data['revenue'])): ?>
            <h2>درآمد</h2>
            <table>
                <tr>
                    <th>نوع</th>
                    <th>مبلغ</th>
                </tr>
                <tr>
                    <td>درآمد کل</td>
                    <td><?= number_format((float)$data['revenue']['income']['total'], 0) ?></td>
                </tr>
                <tr>
                    <td>هزینه‌ها</td>
                    <td><?= number_format((float)$data['revenue']['expenses']['total'], 0) ?></td>
                </tr>
                <tr style="font-weight: bold; background: #e8f5e9;">
                    <td>سود خالص</td>
                    <td><?= number_format((float)$data['revenue']['net_profit'], 0) ?></td>
                </tr>
            </table>
            <?php endif; ?>

            <div class="footer">
                <p>این گزارش به صورت خودکار تولید شده است</p>
            </div>

            <script>
                window.print();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    private function generateExcelHTML(array $data): string
    {
        $html = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $html .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $html .= '<html><body>';
        $html .= '<table border="1">';
        
        // Headers
        $html .= '<tr><td colspan="2" style="font-weight:bold; font-size:14px;">گزارش تحلیلات سیستم</td></tr>';
        $html .= '<tr><td>تاریخ:</td><td>' . date('Y-m-d H:i:s') . '</td></tr>';
        $html .= '<tr><td colspan="2"></td></tr>';
        
        // User data
        if (!empty($data['users'])) {
            $html .= '<tr><td colspan="2" style="font-weight:bold;">تحلیلات کاربران</td></tr>';
            $html .= '<tr><td>کل کاربران</td><td>' . $data['users']['total_users'] . '</td></tr>';
            $html .= '<tr><td>کاربران فعال</td><td>' . $data['users']['active_users'] . '</td></tr>';
        }
        
        // Transaction data
        if (!empty($data['transactions'])) {
            $html .= '<tr><td colspan="2" style="font-weight:bold;">تراکنش‌ها</td></tr>';
            $html .= '<tr><td>درآمد</td><td>' . number_format((float)$data['transactions']['deposits']['amount'], 0) . '</td></tr>';
        }
        
        $html .= '</table></body></html>';
        return $html;
    }
}
