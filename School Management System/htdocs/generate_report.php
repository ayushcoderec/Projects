<?php
require_once 'config.php';
require_once 'functions.php';

checkLogin();

// Helper Functions
/**
 * Calculates and stores class rankings for a specific exam.
 * ---
 * 1. CUMULATIVE: If the exam is 'final' or 'annual', it calculates a CUMULATIVE rank
 * by summing up all `term_total_marks` for the entire session.
 * 2. TERM: If it's a regular term exam, it ranks
 * based ONLY on that exam's marks from the `term_total_marks` table.
 * 3. TIE-BREAKING: Uses "Competition Ranking" (e.g., 1, 2, 2, 4, 5).
 */
function calculateClassRankings($exam_id, $class_id) {
    global $pdo;
    
    // 1. Get exam details to check if it's 'final'
    $exam_stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
    $exam_stmt->execute([$exam_id]);
    $exam = $exam_stmt->fetch();

    if (!$exam) {
        return; // Exam not found
    }

    // 2. Determine if it's a final/annual exam
    $isFinalExam = false;
    if (stripos($exam['name'], 'annual') !== false || 
        stripos($exam['name'], '3rd') !== false || 
        stripos($exam['name'], 'final') !== false) {
        $isFinalExam = true;
    }

    $sql = "";
    $params = [];

    if ($isFinalExam) {
        // --- LOGIC FOR FINAL EXAM (CUMULATIVE) ---

        // 3a. Get academic session dates from 'academic_batches' table
        $batch_stmt = $pdo->prepare("
            SELECT start_date, end_date FROM academic_batches 
            WHERE ? >= start_date AND ? <= end_date
            AND batch_status = 'active'
            LIMIT 1
        ");
        $batch_stmt->execute([$exam['start_date'], $exam['end_date']]); 
        $current_batch = $batch_stmt->fetch();

        // Fallback if no active batch is found
        if (!$current_batch) {
            $exam_year = (int)date('Y', strtotime($exam['start_date']));
            $exam_month = (int)date('m', strtotime($exam['start_date']));
            $session_start_year = ($exam_month < 4) ? $exam_year - 1 : $exam_year; 
            $session_start_date = $session_start_year . '-04-01';
            $session_end_date = ($session_start_year + 1) . '-03-31';
        } else {
            $session_start_date = $current_batch['start_date'];
            $session_end_date = $current_batch['end_date'];
        }

        // 3b. Final Exam (Cumulative) Query
        // This query sums up ALL marks from the `term_total_marks` table
        $sql = "
            SELECT 
                s.id as student_id,
                s.class_id,
                SUM(COALESCE(ttm.total_marks, 0)) as total_marks,
                SUM(COALESCE(ttm.total_full_marks, 0)) as total_full_marks
            FROM students s
            LEFT JOIN term_total_marks ttm ON s.id = ttm.student_id
            LEFT JOIN exams e ON ttm.exam_id = e.id
            WHERE s.class_id = ?
              AND e.start_date BETWEEN ? AND ?
            GROUP BY s.id
            HAVING total_marks > 0 OR total_full_marks > 0
            ORDER BY total_marks DESC
        ";
        $params = [$class_id, $session_start_date, $session_end_date];

    } else {
        // --- (FIXED) LOGIC FOR TERM EXAM (NON-CUMULATIVE) ---
        // This now reads directly from term_total_marks for THIS exam.
        // It assumes you have generated the report for all students in the class
        // at least once to populate the term_total_marks table.
        $sql = "
            SELECT 
                s.id as student_id,
                s.class_id,
                COALESCE(ttm.total_marks, 0) as total_marks,
                COALESCE(ttm.total_full_marks, 0) as total_full_marks
            FROM students s
            LEFT JOIN term_total_marks ttm ON s.id = ttm.student_id AND ttm.exam_id = ?
            WHERE s.class_id = ?
            GROUP BY s.id
            ORDER BY total_marks DESC
        ";
        // Params are [exam_id, class_id]
        $params = [$exam_id, $class_id];
    }

    // 4. Execute the chosen query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    
    // 5. Delete existing rankings for this specific exam_id and class_id
    $pdo->prepare("DELETE FROM class_rankings WHERE exam_id = ? AND class_id = ?")->execute([$exam_id, $class_id]);
    
    // 6. Insert new rankings (with correct tie handling: 1, 2, 2, 4, 5)
    $rank = 0;
    $students_processed = 0;
    $prev_marks = -1;
    
    foreach ($students as $student) {
        $students_processed++;
        
        // If marks are different from previous, update rank to current position
        if ($student['total_marks'] != $prev_marks) {
            $rank = $students_processed;
        }
        
        // Skip students with 0 marks
        if ($student['total_marks'] <= 0 && $student['total_full_marks'] <= 0) {
            $rank = 0; // Don't rank students with no marks
            continue;
        }
        
        $percentage = $student['total_full_marks'] > 0 
            ? ($student['total_marks'] / $student['total_full_marks']) * 100 : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO class_rankings (student_id, exam_id, class_id, total_marks, percentage, class_rank)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $student['student_id'],
            $exam_id,
            $class_id,
            $student['total_marks'],
            $percentage,
            $rank // Use the calculated rank
        ]);
        
        $prev_marks = $student['total_marks'];
    }
}

function getStudentRank($student_id, $exam_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT class_rank, total_marks, percentage 
        FROM class_rankings 
        WHERE student_id = ? AND exam_id = ?
    ");
    $stmt->execute([$student_id, $exam_id]);
    return $stmt->fetch();
}

function getFirstRanker($exam_id, $class_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT s.name, cr.total_marks, cr.percentage
        FROM class_rankings cr
        JOIN students s ON cr.student_id = s.id
        WHERE cr.exam_id = ? AND cr.class_id = ? AND cr.class_rank = 1
    ");
    $stmt->execute([$exam_id, $class_id]);
    return $stmt->fetch();
}

function getWorkingDays($exam_id, $class_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT working_days 
        FROM exam_working_days 
        WHERE exam_id = ? AND class_id = ?
    ");
    $stmt->execute([$exam_id, $class_id]);
    $result = $stmt->fetch();
    return $result ? $result['working_days'] : 220;
}

function getStudentAttendance($student_id, $exam_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT present_days 
        FROM student_exam_attendance 
        WHERE student_id = ? AND exam_id = ?
    ");
    $stmt->execute([$student_id, $exam_id]);
    $result = $stmt->fetch();
    return $result ? $result['present_days'] : 0;
}

function getPreviousTermMarks($student_id, $exam_id) {
    global $pdo;
    
    // Get all term marks BEFORE the current exam
    $stmt = $pdo->prepare("
        SELECT DISTINCT ttm.term_name, ttm.total_marks, e.name as exam_name
        FROM term_total_marks ttm
        JOIN exams e ON ttm.exam_id = e.id
        WHERE ttm.student_id = ? AND ttm.exam_id != ? AND e.start_date < (SELECT start_date FROM exams WHERE id = ?)
        ORDER BY e.start_date ASC
    ");
    $stmt->execute([$student_id, $exam_id, $exam_id]);
    return $stmt->fetchAll();
}

function saveTermTotalMarks($student_id, $exam_id, $total_marks, $total_full_marks, $term_name) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO term_total_marks (student_id, exam_id, total_marks, total_full_marks, term_name)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                total_marks = VALUES(total_marks), 
                total_full_marks = VALUES(total_full_marks), 
                term_name = VALUES(term_name)
        ");
        $stmt->execute([$student_id, $exam_id, $total_marks, $total_full_marks, $term_name]);
    } catch (Exception $e) {
        // Silently fail if table doesn't exist or column is missing
        error_log("saveTermTotalMarks Error: " . $e->getMessage());
    }
}
// Grade calculation function
function getGrade($percentage) {
    if ($percentage >= 90) return 'A++';
    elseif ($percentage >= 80) return 'A+';
    elseif ($percentage >= 70) return 'A';
    elseif ($percentage >= 60) return 'B+';
    elseif ($percentage >= 50) return 'B';
    elseif ($percentage >= 40) return 'C+';
    elseif ($percentage >= 30) return 'C';
    else return 'D';
}

function getRemarks($grade) {
    switch ($grade) {
        case 'A++': return 'Outstanding';
        case 'A+': return 'Excellent';
        case 'A': return 'Very Good';
        case 'B+': return 'Good, keep it up';
        case 'B': return 'Satisfactory';
        case 'C+': return 'Needs Improvement';
        case 'C': return 'Try Harder';
        default: return 'Poor';
    }
}

// Enhanced report generation function
function generateComprehensiveReport($student, $scholastic_marks, $co_scholastic_marks, $exam, $class, $colorMode = 'color') {
    global $pdo;
    
    $totalMarks = 0;
    $totalFullMarks = 0;
    $subjectCount = count($scholastic_marks);
    $passedSubjects = 0;
    
    // Calculate scholastic totals
    foreach ($scholastic_marks as $mark) {
        $currentMarks = floatval($mark['marks'] ?? 0);
        // 🛠️ FIXED: Use the correct full marks passed from the query
        $currentFullMarks = floatval($mark['written_full_marks']) + floatval($mark['oral_full_marks']);
        
        $totalMarks += $currentMarks;
        $totalFullMarks += $currentFullMarks;
        
        $subjectPercentage = $currentFullMarks > 0 ? ($currentMarks / $currentFullMarks) * 100 : 0;
        if ($subjectPercentage >= 30) {
            $passedSubjects++;
        }
    }
    
    
    // 🛠️ NEW: Calculate Grand Totals (Scholastic + Co-Scholastic) for the summary box
    $grandTotalObtained = $totalMarks; // Start with scholastic total
    $grandTotalFull = $totalFullMarks; // Start with scholastic full marks
    
    if (!empty($co_scholastic_marks)) {
        foreach ($co_scholastic_marks as $mark) {
            // Add co-scholastic obtained marks
            $grandTotalObtained += floatval($mark['marks'] ?? 0);
            
            // Add co-scholastic full marks (fetched using the same query logic)
            $grandTotalFull += floatval($mark['written_full_marks']) + floatval($mark['oral_full_marks']);
        }
    }
        $overallPercentage = $totalFullMarks > 0 ? ($grandTotalObtained / $grandTotalFull) * 100 : 0;
    $result = ($overallPercentage >= 30 && $passedSubjects == $subjectCount) ? 'PASS' : 'FAIL';
    // 🛠️ END: Grand Total Calculation
    
    // Determine term name from exam name
    $termName = '1st Term';
    if (stripos($exam['name'], '2nd') !== false || stripos($exam['name'], 'second') !== false || stripos($exam['name'], 'mid') !== false) {
        $termName = '2nd Term';
    } elseif (stripos($exam['name'], 'annual') !== false || stripos($exam['name'], '3rd') !== false || stripos($exam['name'], 'final') !== false) {
        $termName = 'Annual';
    }
    
    // Save current term marks (now including total_full_marks)
    // THIS MUST HAPPEN *BEFORE* CALCULATING RANKINGS
    saveTermTotalMarks($student['id'], $exam['id'], $totalMarks, $totalFullMarks, $termName);
    
    // Get/Calculate rankings
    // This will now work for Term 1, Term 2, and Final
    calculateClassRankings($exam['id'], $student['class_id']);
    $rankData = getStudentRank($student['id'], $exam['id']);
    $firstRanker = getFirstRanker($exam['id'], $student['class_id']);
    
    // Get attendance data
    $workingDays = getWorkingDays($exam['id'], $student['class_id']);
    $presentDays = getStudentAttendance($student['id'], $exam['id']);
    $attendancePercentage = $workingDays > 0 ? ($presentDays / $workingDays) * 100 : 0;
    
    // Get previous term marks
    $previousTerms = getPreviousTermMarks($student['id'], $exam['id']);
    
    // Color scheme
    $colors = $colorMode === 'color' ? [
        'primary' => '#2c5aa0',
        'success' => '#28a745',
        'danger' => '#dc3545',
        'warning' => '#ffc107',
        'info' => '#17a2b8',
        'light' => '#f8f9fa',
        'table_header' => 'linear-gradient(135deg, #2c5aa0, #1e3d72)',
        'co_scholastic_bg' => '#e8f5e8'
    ] : [
        'primary' => '#000',
        'success' => '#666',
        'danger' => '#666',
        'warning' => '#666',
        'info' => '#666',
        'light' => '#f5f5f5',
        'table_header' => '#666',
        'co_scholastic_bg' => '#f0f0f0'
    ];
    
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Progress Report - {$student['name']}</title>
        <style>
            @page { size: A4; margin: 10mm; }
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: Arial, sans-serif; 
                font-size: 10px; 
                line-height: 1.2;
                color: #333;
                background: #fff;
            }
            
            .print-controls {
                position: fixed;
                top: 10px;
                right: 10px;
                z-index: 1000;
                display: flex;
                gap: 10px;
            }
            .print-controls button {
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
                font-weight: bold;
            }
            .btn-print { background: #28a745; color: white; }
            .btn-color-toggle { background: #17a2b8; color: white; }
            
            .header {
                text-align: center;
                border: 2px solid {$colors['primary']};
                padding: 10px;
                margin-bottom: 15px;
            }
            .school-name { 
                font-size: 25px; 
                font-weight: bold; 
                color: {$colors['primary']};
                margin-bottom: 5px;
            }
            .school-name span {
                font-size: 12px;
                display: block;
            }
            .school-address {
                font-size: 10px;
                color: #000;
                margin-bottom: 8px;
            }
            .report-title { 
                font-size: 14px; 
                font-weight: bold;
                background: #fff;
                color: " . ($colorMode === 'color' ? '#2c5aa0' : '#333') . ";
                padding: 8px;
                margin: 8px 0;
                border-radius: 4px;
            }
            
            .student-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 15px;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 8px;
                background: {$colors['light']};
            }
            .info-column {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .info-item {
                display: flex;
                justify-content: space-between;
                padding: 3px 0;
                border-bottom: 1px dotted #ccc;
                font-size: 9px;
            }
            .info-label { font-weight: bold;color:#000; }
            .info-value { color: {$colors['primary']}; font-weight: bold; }
            
            .section-title {
                background: #fff;
                color: " . ($colorMode === 'color' ? '#2c5aa0' : '#333') . ";
                padding: 8px;
                font-weight: bold;
                text-align: center;
                margin: 15px 0 5px;
                font-size: 12px;
                border:2px solid {$colors['primary']};
            }
            
            .marks-table {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0;
                font-size: 9px;
            }
            .marks-table th {
                background: #fff;
                color: " . ($colorMode === 'color' ? '#000' : '#333') . ";
                padding: 6px 4px;
                text-align: center;
                border: 1px solid #333;
                font-size: 8px;
                font-weight: bold;
            }
            .marks-table td {
                padding: 5px 4px;
                text-align: center;
                border: 1px solid #000;
                font-size: 9px;
                color:#000;
            }
            .marks-table tr:nth-child(even) {
                background: " . ($colorMode === 'color' ? '#fff' : '#f5f5f5') . ";
            }
            .subject-name {
                text-align: left !important;
                padding-left: 8px !important;
                font-weight: 600;
            }
            .total-row {
                background: #fff !important;
                color: " . ($colorMode === 'color' ? 'white' : '#333') . ";
                font-weight: bold;
                font-size: 10px;
            }
            
            .co-scholastic-section {
                background: {$colors['co_scholastic_bg']};
                padding: 10px;
                border-radius: 8px;
                margin: 15px 0;
            }
            
            .co-scholastic-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-top: 10px;
            }
            
            .co-scholastic-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8px;
            }
            .co-scholastic-table th {
                background: #fff;
                color: #000;
                padding: 4px;
                text-align: center;
                border: 1px solid #000;
                font-size: 7px;
            }
            .co-scholastic-table td {
                padding: 4px;
                text-align: center;
                border: 1px solid #000;
                font-size: 8px;
                color:#000;
            }
            
            .summary-section {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 10px;
                margin: 15px 0;
            }
            .summary-card {
                border: 1px solid #ddd;
                padding: 12px;
                text-align: center;
                font-size: 10px;
                border-radius: 6px;
            }
            .summary-card.result-pass {
                background: " . ($colorMode === 'color' ? 'linear-gradient(135deg, #d4edda, #c3e6cb)' : '#f0f0f0') . ";
                border-color: {$colors['success']};
            }
            .summary-card.result-fail {
                background: " . ($colorMode === 'color' ? 'linear-gradient(135deg, #f8d7da, #f5c6cb)' : '#e8e8e8') . ";
                border-color: {$colors['danger']};
            }
            .summary-value {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 5px;
                color: {$colors['primary']};
            }
            .summary-label {
                font-size: 9px;
                color: #000;
                text-transform: uppercase;
            }
            
            .remarks-section {
                margin: 15px 0;
                padding: 12px;
                background: " . ($colorMode === 'color' ? 'linear-gradient(135deg, #fff3cd, #ffeaa7)' : '#f5f5f5') . ";
                border-left: 4px solid " . ($colorMode === 'color' ? '#f39c12' : '#666') . ";
                border-radius: 6px;
            }
            .remarks-title {
                font-weight: bold;
                color: " . ($colorMode === 'color' ? '#856404' : '#333') . ";
                margin-bottom: 8px;
                font-size: 10px;
            }
            .remarks-content {
                height: 25px;
                border-bottom: 1px dotted #999;
                margin: 8px 0;
            }
            
            .signature-section {
                display: flex;
                justify-content: space-between;
                margin-top: 20px;
            }
            .signature-box {
                text-align: center;
                width: 150px;
            }
            .signature-line {
                border-bottom: 1px solid #333;
                height: 35px;
                margin-bottom: 6px;
            }
            .signature-label {
                font-size: 8px;
                font-weight: bold;
                color: #000;
            }
            
            .footer {
                text-align: center;
                font-size: 8px;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #ddd;
                color: #000;
            }
            
            .previous-terms-box {
                border: 2px solid {$colors['primary']};
                padding: 10px;
                border-radius: 8px;
                background: {$colors['light']};
                font-size: 9px;
                margin: 10px 0;
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
            }
            
            @media print {
                .print-controls { display: none !important; }
                body { background: white !important; }
            }
        </style>
    </head>
    <body>
        <!-- Print Controls -->
        <div class='print-controls'>
            <button class='btn-color-toggle' onclick='toggleColorMode()'>
                " . ($colorMode === 'color' ? 'Switch to B&W' : 'Switch to Color') . "
            </button>
            <button class='btn-print' onclick='window.print()'>
                🖨️ Print Report
            </button>
        </div>

        <!-- Header -->
        <div class='header'>
            <div class='school-name'>" . (APP_NAME ?? 'Nightingale Nursery School') . "
                <span>A School Based On English & Computer</span>
            </div>
            <div class='school-address'>
                <b>U-DISE CODE:</b> 19160701609 | <b>Reg.No:</b> SL/1L/64114
            </div>
            <div class='report-title'>{$exam['name']}</div>
        </div>

        <!-- Student Information -->
        <div class='student-info'>
            <div class='info-column'>
                <div class='info-item'>
                    <span class='info-label'>Student Name:</span>
                    <span class='info-value'>{$student['name']}</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Admission No:</span>
                    <span class='info-value'>" . ($student['admission_number'] ?? 'N/A') . "</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Student ID:</span>
                    <span class='info-value'>{$student['student_id']}</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Roll Number:</span>
                    <span class='info-value'>{$student['roll_number']}</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Class:</span>
                    <span class='info-value'>{$class['name']} - {$class['section']}</span>
                </div>
            </div>
            <div class='info-column'>
                <div class='info-item'>
                    <span class='info-label'>Father's Name:</span>
                    <span class='info-value'>" . ($student['father_name'] ?? 'N/A') . "</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Mother's Name:</span>
                    <span class='info-value'>" . ($student['mother_name'] ?? 'N/A') . "</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Address:</span>
                    <span class='info-value'>" . (substr($student['address'] ?? 'N/A', 0, 30)) . "</span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Session:</span>
                    <span class='info-value'>" . date('Y') . "</span>
                </div>
            </div>
        </div>";

    // Previous Terms Box
    if (!empty($previousTerms)) {
        $html .= "<div class='previous-terms-box'>";
        $html .= "<div><strong>Previous Term Performance:</strong></div>";
        $html .= "<div>";
        foreach ($previousTerms as $prevTerm) {
            $html .= "<span style='margin-right: 20px; display: inline-block;'>";
            $html .= "<strong>{$prevTerm['term_name']}:</strong> " . number_format($prevTerm['total_marks'], 1) . " marks";
            $html .= "</span>";
        }
        $html .= "</div></div>";
    }

    $html .= "
        <!-- Academic Performance (Scholastic Areas) -->
        <div class='section-title'>Academic Performance (Scholastic Areas)</div>
        <table class='marks-table'>
            <thead>
                <tr>
                    <th rowspan='2' style='width: 25%;'>Subject's Name</th>
                    <th colspan='2' style='width: 18%;'>Full Marks</th>
                    <th colspan='2' style='width: 18%;'>Marks Obtained</th>
                    <th rowspan='2' style='width: 10%;'>Grade</th>
                    <th rowspan='2' style='width: 12%;'>Total<br>Marks</th>
                    <th rowspan='2' style='width: 17%;'>Remarks</th>
                </tr>
                <tr>
                    <th>W</th>
                    <th>O</th>
                    <th>W</th>
                    <th>O</th>
                </tr>
            </thead>
            <tbody>";
    
    // Display scholastic subjects
    foreach ($scholastic_marks as $mark) {
        $writtenMarks = $mark['written_marks'] ?? 0;
        $oralMarks = $mark['oral_marks'] ?? 0;
        // 🛠️ FIXED: Use the correct full marks
        $writtenFull = $mark['written_full_marks'];
        $oralFull = $mark['oral_full_marks'];
        $totalMarksSubject = $writtenMarks + $oralMarks;
        $totalFullSubject = $writtenFull + $oralFull;
        $percentage = $totalFullSubject > 0 ? ($totalMarksSubject / $totalFullSubject) * 100 : 0;
        $grade = getGrade($percentage);
        $remarks = getRemarks($grade);
        
        $html .= "
            <tr>
                <td class='subject-name'>{$mark['subject_name']}</td>
                <td>$writtenFull</td>
                <td>$oralFull</td>
                <td>" . ($writtenMarks > 0 ? number_format($writtenMarks, 1) : '-') . "</td>
                <td>" . ($oralMarks > 0 ? number_format($oralMarks, 1) : '-') . "</td>
                <td><strong>$grade</strong></td>
                <td>" . number_format($totalMarksSubject, 1) . "</td>
                <td>$remarks</td>
            </tr>";
    }
    
    $overallGrade = getGrade($overallPercentage);
    
    $html .= "
                <tr class='total-row'>
                    <td><strong>TOTAL</strong></td>
                    <td colspan='4'><strong>Overall Result</strong></td>
                    <td><strong>$overallGrade</strong></td>
                    <td><strong>" . number_format($totalMarks, 1) . "</strong></td>
                    <td><strong>" . ($result === 'PASS' ? 'PASS' : 'FAIL') . "</strong></td>
                </tr>
            </tbody>
        </table>";

    // Co-Scholastic Areas
    if (!empty($co_scholastic_marks)) {
         $html .= "
         <div class='co-scholastic-section'>
            <div class='section-title' style='margin-top:0; background: {$colors['success']};'>Co-Scholastic Areas</div>
            <div class='co-scholastic-grid'>
                <table class='co-scholastic-table'>
                    <thead>
                        <tr>
                            <th>Area</th>
                            <th>Marks</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>";
        
        $half = ceil(count($co_scholastic_marks) / 2);
        for ($i = 0; $i < $half; $i++) {
            $mark = $co_scholastic_marks[$i];
            $grade = getGrade($mark['marks']);
            $remarks = getRemarks($grade);
             $html .= "
                <tr>
                    <td style='text-align: left; padding-left: 8px;color:#000;'>{$mark['subject_name']}</td>
                    <td><strong>{$mark['marks']}</strong></td>
                    <td>{$remarks}</td>
                </tr>";
        }

        $html .= "
                    </tbody>
                </table>
                <table class='co-scholastic-table'>
                    <thead>
                        <tr>
                            <th>Area</th>
                            <th>Marks</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>";

        for ($i = $half; $i < count($co_scholastic_marks); $i++) {
            $mark = $co_scholastic_marks[$i];
            $grade = getGrade($mark['marks']);
            $remarks = getRemarks($grade);
             $html .= "
                <tr>
                    <td style='text-align: left; padding-left: 8px;color:#000;'>{$mark['subject_name']}</td>
                    <td><strong>{$mark['marks']}</strong></td>
                    <td>{$remarks}</td>
                </tr>";
        }
        
        $html .= "
                    </tbody>
                </table>
            </div>
        </div>";
    }

    $html .= "
        <!-- Summary Section -->
        <div class='summary-section'>
            <div class='summary-card'>
                <div class='summary-value'>" . number_format($overallPercentage, 1) . "%</div>
                <div class='summary-label'>Overall Percentage</div>
            </div>
            <div class='summary-card'>
                <div class='summary-value'>$overallGrade</div>
                <div class='summary-label'>Overall Grade</div>
            </div>
            <div class='summary-card result-" . strtolower($result) . "'>
                <div class='summary-value'>$result</div>
                <div class='summary-label'>Final Result</div>
            </div>
        </div>

        <!-- Working Days & Attendance with Rank -->
        <div style='display: flex; justify-content: space-between; margin: 15px 0; font-size: 9px;color:#000;'>
            <div style='border: 1px solid #ddd; padding: 10px; width: 28%; text-align: center; border-radius: 6px;'>
                <strong>Attendance</strong><br>
                <strong>Working Days:</strong> $workingDays<br>
                <strong>Present:</strong> $presentDays<br>
                <strong>Percentage:</strong> " . number_format($attendancePercentage, 1) . "%
            </div>
           <!-- 🛠️ MODIFIED: This is the updated Marks Summary box -->
            <div style='border: 1px solid #ddd; padding: 10px; width: 28%; text-align: center; border-radius: 6px;color:#000;'>
                <strong>Marks Summary (Scholastic + Co)</strong><br>
                <strong>Full Marks:</strong> $grandTotalFull<br>
                <strong>Pass Marks:</strong> " . round($grandTotalFull * 0.30) . "<br>
                <strong>Obtained:</strong> " . number_format($grandTotalObtained, 1) . "
            </div>
            <!-- 🛠️ END: Modified Box -->
            <div style='border: 1px solid #ddd; padding: 10px; width: 40%; text-align: center; border-radius: 6px; background: {$colors['light']};'>
                <strong style='font-size: 10px;'>Class Performance</strong><br>
                <strong>Rank:</strong> <span style='font-size: 18px; color: {$colors['primary']};'>" . ($rankData && $rankData['class_rank'] > 0 ? $rankData['class_rank'] : 'N/A') . "</span><br>
                <strong>First Ranker:</strong> " . ($firstRanker['name'] ?? 'N/A') . "<br>
                <small>(" . ($firstRanker ? number_format($firstRanker['total_marks'], 1) : 'N/A') . " marks)</small>
            </div>
        </div>

        <!-- Teacher's Remarks -->
        <div class='remarks-section'>
            <div class='remarks-title'>Remarks of Class Teacher with Sign. & Date:</div>
            <div class='remarks-content'></div>
        </div>

        <!-- Signature Section -->
        <div class='signature-section'>
            <div class='signature-box'>
                <div class='signature-line'></div>
                <div class='signature-label'>Sign of Principal with date</div>
            </div>
            <div class='signature-box'>
                <div class='signature-line'></div>
                <div class='signature-label'>Sign of Guardian with date</div>
            </div>
        </div>

        <!-- Footer -->
        <div class='footer'>
            <div>
                <strong>Report Generated on:</strong> " . date('F d, Y') . " | 
                <strong>Academic Session:</strong> " . date('Y') . "-" . (date('Y')+1) . "
            </div>
            <div style='margin-top: 5px;'>
                <strong>GRADE SCALE:</strong> A++ (90-100), A+ (80-89), A (70-79), B+ (60-69), B (50-59), C+ (40-49), C (30-39), D (Below 30)
            </div>
        </div>

        <script>
            function toggleColorMode() {
                const currentMode = '" . $colorMode . "';
                const newMode = currentMode === 'color' ? 'bw' : 'color';
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('color_mode', newMode);
                window.location.search = urlParams.toString();
            }
            
            // 🛠️ ADDED: Auto-print for individual reports
            // This script will only run if it's NOT a bulk print
            window.addEventListener('load', function() {
                // Find the auto-print button and click it
                const printButton = document.querySelector('.btn-print');
                if (printButton) {
                    // printButton.click(); // Commented out for easier debugging
                }
            });
        </script>
    </body>
    </html>";
    
    return $html;
}


// 🛠️ --- THIS IS THE MAIN LOGIC --- 🛠️

// Define a common query structure for fetching marks
function getMarksQuery($student_id, $exam_id, $subject_type, $class_id) {
    // 🛠️ DEFINE: Logic to get correct full marks (Custom or Default)
    $written_marks_coalesce = "COALESCE(csc.written_full_marks, s.written_full_marks)";
    $oral_marks_coalesce = "COALESCE(csc.oral_full_marks, s.oral_full_marks)";
    
    // 🛠️ DEFINE: Join to the new custom marks table
    $join_config = "LEFT JOIN subject_class_full_marks csc ON s.id = csc.subject_id AND csc.class_id = :class_id";

    return "
        SELECT 
            m.id,
            m.written_marks, 
            m.oral_marks, 
            m.marks,
            s.name as subject_name, 
            {$written_marks_coalesce} as written_full_marks, 
            {$oral_marks_coalesce} as oral_full_marks,
            s.display_order
        FROM marks m 
        JOIN subjects s ON m.subject_id = s.id 
        {$join_config}
        WHERE m.student_id = :student_id 
          AND m.exam_id = :exam_id 
          AND s.subject_type = :subject_type
        ORDER BY s.display_order ASC, s.name ASC
    ";
}


// Handle individual student report
if (isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];
    $colorMode = $_GET['color_mode'] ?? 'color';
    // 🛠️ FIXED: Ensure exam_id is fetched from GET
    $exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : null; 
    
    // Get student details with all fields
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, c.section as class_section, c.id as class_id
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        die('Student not found');
    }

    $class_id = $student['class_id']; // Get class_id for the query
    
    // Get specific exam or most recent exam
    if (!$exam_id) {
        // If no exam_id is passed, get the most recent one (original logic)
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.* FROM exams e 
            JOIN marks m ON e.id = m.exam_id 
            WHERE m.student_id = ? 
            ORDER BY e.start_date DESC 
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $exam = $stmt->fetch();
    } else {
        // If exam_id is passed, use it
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();
    }
    
    if (!$exam) {
        die('No exam records found for this student');
    }
    
    // Get class details
    $class = [
        'name' => $student['class_name'],
        'section' => $student['class_section']
    ];
    
    // QC: Make sure we pass the correct class_id
    if (empty($class_id)) {
        die('Student is not assigned to a class.');
    }
    
    // 🛠️ FIXED: Get ALL scholastic marks for this student and exam
    $sql_scholastic = getMarksQuery($student_id, $exam['id'], 'scholastic', $class_id);
    $stmt = $pdo->prepare($sql_scholastic);
    $stmt->execute([
        'student_id' => $student_id, 
        'exam_id' => $exam['id'], 
        'class_id' => $class_id, 
        'subject_type' => 'scholastic'
    ]);
    $scholastic_marks = $stmt->fetchAll();
    
    // Get CO-SCHOLASTIC marks
    $sql_co_scholastic = getMarksQuery($student_id, $exam['id'], 'co_scholastic', $class_id);
    $stmt = $pdo->prepare($sql_co_scholastic);
    $stmt->execute([
        'student_id' => $student_id, 
        'exam_id' => $exam['id'], 
        'class_id' => $class_id, 
        'subject_type' => 'co_scholastic'
    ]);
    $co_scholastic_marks = $stmt->fetchAll();
    
    if (empty($scholastic_marks)) {
        // Don't die, just show an empty report
        // die('No scholastic marks found for this student in the selected exam');
    }
    
    // Generate and output the comprehensive report
    $reportContent = generateComprehensiveReport($student, $scholastic_marks, $co_scholastic_marks, $exam, $class, $colorMode);
    
    header('Content-Type: text/html; charset=UTF-8');
    echo $reportContent;
    exit;
}

// Bulk report generation
if (isset($_GET['bulk']) && isset($_GET['class_id']) && isset($_GET['exam_id'])) {
    $class_id = (int)$_GET['class_id'];
    $exam_id = (int)$_GET['exam_id'];
    $colorMode = $_GET['color_mode'] ?? 'color';
    
    // Get class and exam details
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch();
    
    if (!$class || !$exam) {
        die('Invalid class or exam');
    }
    
    // Get students in the class with full details
    $stmt = $pdo->prepare("SELECT * FROM students WHERE class_id = ? ORDER BY CAST(roll_number AS UNSIGNED) ASC");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll();
    
    if (empty($students)) {
        die('No students found in this class');
    }
    
    // Generate combined HTML for bulk printing
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Bulk Reports for {$class['name']}</title>
    <style>
        .page-break { page-break-after: always; } 
        @media print { 
            .page-break:last-child { page-break-after: avoid; } 
            .print-controls { display: none !important; } /* Hide controls in bulk print */
        }
    </style>
    </head><body>";
    
    $reportCount = 0;
    foreach ($students as $student) {
        $student_id = $student['id'];

        // 🛠️ FIXED: Get ALL scholastic marks
        $sql_scholastic = getMarksQuery($student_id, $exam_id, 'scholastic', $class_id);
        $stmt = $pdo->prepare($sql_scholastic);
        $stmt->execute([
            'student_id' => $student_id, 
            'exam_id' => $exam_id, 
            'class_id' => $class_id, 
            'subject_type' => 'scholastic'
        ]);
        $scholastic_marks = $stmt->fetchAll();
        
        // Get co-scholastic marks
        $sql_co_scholastic = getMarksQuery($student_id, $exam_id, 'co_ scholastic', $class_id);
        $stmt = $pdo->prepare($sql_co_scholastic);
        $stmt->execute([
            'student_id' => $student_id, 
            'exam_id' => $exam_id, 
            'class_id' => $class_id, 
            'subject_type' => 'co_scholastic'
        ]);
        $co_scholastic_marks = $stmt->fetchAll();
        
        if (!empty($scholastic_marks)) {
            // We need to pass the student's full data array
            $student['class_id'] = $class_id; // Ensure class_id is in the student array
            $student['class_name'] = $class['name']; // Add class name
            $student['class_section'] = $class['section']; // Add class section
            
            $reportContent = generateComprehensiveReport($student, $scholastic_marks, $co_scholastic_marks, $exam, $class, $colorMode);
            
            // 🛠️ REMOVED: Auto-print script from bulk reports
            $reportContent = str_replace("window.addEventListener('load', function() {", "/* Auto-print disabled for bulk */ window.addEventListener('load-disabled', function() {", $reportContent);
            
            echo $reportContent;
            
            $reportCount++;
            if ($reportCount < count($students)) {
                echo '<div class="page-break"></div>';
            }
        }
    }
    
    // 🛠️ ADDED: Auto-print script for the bulk page itself
    echo "<script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                // window.print(); // Commented out for easier debugging
            }, 1000);
        });
    </script>";
    echo "</body></html>";
    exit;
}

// If no action, redirect
header("Location: view_results.php");
exit;
