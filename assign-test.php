<?php
session_start();
require_once('db.php');

$message = '';

// HANDLE ASSIGNMENT
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $qp_id = filter_input(INPUT_POST, 'qp_id', FILTER_VALIDATE_INT);
    $selected_students = $_POST['students'] ?? [];

    if ($qp_id && !empty($selected_students)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO student_test_allocation (student_id, qp_id) VALUES (:sid, :qid) ON CONFLICT (student_id, qp_id) DO NOTHING");
            $count = 0;
            foreach ($selected_students as $sid) {
                $stmt->execute([':sid' => $sid, ':qid' => $qp_id]);
                if ($stmt->rowCount() > 0) $count++;
            }
            $pdo->commit();
            $message = "<div class='msg success'>✅ Assigned to $count student(s)!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='msg error'>Error: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='msg error'>Select a Test and Students.</div>";
    }
}

// FETCH DATA
$semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll();
// Subjects
$sub_res = $pdo->query("SELECT id, name, semester_id FROM subjects WHERE semester_id IS NOT NULL");
$subs_by_sem = [];
foreach($sub_res as $r) $subs_by_sem[$r['semester_id']][] = $r;
// Question Papers
$qp_res = $pdo->query("SELECT id, title, subject_id FROM question_papers WHERE subject_id IS NOT NULL");
$qps_by_sub = [];
foreach($qp_res as $r) $qps_by_sub[$r['subject_id']][] = $r;
// Students (Directly from students table)
$stu_res = $pdo->query("SELECT id, student_name, semester FROM students WHERE semester IS NOT NULL");
$stus_by_sem = [];
foreach($stu_res as $r) $stus_by_sem[$r['semester']][] = $r;

$json_subs = json_encode($subs_by_sem);
$json_qps = json_encode($qps_by_sub);
$json_stus = json_encode($stus_by_sem);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Test</title>
    <style>
        body{font-family:sans-serif;padding:20px;background:#2b2d42;color:white;}
        .container{max-width:800px;margin:auto;background:rgba(255,255,255,0.1);padding:20px;border-radius:10px;}
        select,button{width:100%;padding:10px;margin-top:5px;border-radius:5px;}
        .student-box{background:rgba(0,0,0,0.3);padding:10px;max-height:300px;overflow-y:auto;margin-top:10px;}
        .msg{padding:10px;text-align:center;margin-bottom:10px;border-radius:5px;font-weight:bold;}
        .success{background:#d4edda;color:#155724;} .error{background:#f8d7da;color:#721c24;}
    </style>
</head>
<body>
<div class="container">
    <h2>Assign Test</h2>
    <?= $message ?>
    <form method="POST">
        <label>1. Semester:</label>
        <select id="sem_id"><option value="">-- Select --</option>
            <?php foreach($semesters as $s): ?><option value="<?=$s['id']?>"><?=$s['name']?></option><?php endforeach; ?>
        </select>

        <label>2. Subject:</label><select id="sub_id" disabled></select>
        <label>3. Test:</label><select id="qp_id" name="qp_id" disabled required></select>

        <div id="stu_div" style="display:none;">
            <label>4. Students:</label>
            <div id="stu_list" class="student-box"></div>
        </div>

        <button id="btn" disabled>Assign</button>
    </form>
</div>
<script>
const subs = <?=$json_subs?:'{}'?>, qps = <?=$json_qps?:'{}'?>, stus = <?=$json_stus?:'{}'?>;
const elSem=document.getElementById('sem_id'), elSub=document.getElementById('sub_id'), elQp=document.getElementById('qp_id'), elList=document.getElementById('stu_list'), elDiv=document.getElementById('stu_div'), elBtn=document.getElementById('btn');

elSem.onchange = function(){
    const id = this.value;
    elSub.innerHTML='<option value="">-- Select Subject --</option>'; elSub.disabled=true;
    elQp.innerHTML='<option value="">-- Select Test --</option>'; elQp.disabled=true;
    elList.innerHTML=''; elDiv.style.display='none'; elBtn.disabled=true;
    if(!id)return;

    if(subs[id]){ elSub.disabled=false; subs[id].forEach(s=>elSub.add(new Option(s.name,s.id))); }
    if(stus[id]){
        elDiv.style.display='block';
        stus[id].forEach(s=>{
            elList.innerHTML += `<div><label><input type="checkbox" name="students[]" value="${s.id}" checked> ${s.student_name}</label></div>`;
        });
    } else {
        elDiv.style.display='block'; elList.innerHTML='No students found.';
    }
};

elSub.onchange = function(){
    const id = this.value;
    elQp.innerHTML='<option value="">-- Select Test --</option>'; elQp.disabled=true; elBtn.disabled=true;
    if(!id)return;
    if(qps[id]){ elQp.disabled=false; qps[id].forEach(q=>elQp.add(new Option(q.title,q.id))); }
};

elQp.onchange = function(){ elBtn.disabled = !this.value; };
</script>
</body>
</html>
