<?php
// Retained endpoint/JSON layout; rejection events reference the same application.
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/../../connection/config.php';
require_once __DIR__.'/../../connection/pdo.php';
require_once __DIR__.'/../../connection/app.php';
require_once __DIR__.'/../../connection/mailer.php';
header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['finance']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();
gjc_ensure_stall_application_workflow_schema($db);
$action=trim((string)($_POST['action']??$_GET['action']??'list'));
function archived_json(array $payload):void{echo json_encode($payload);exit;}
try{
    if($action==='list'){
        $rows=$db->query("SELECT r.id,r.application_id AS original_application_id,r.rejected_at_step,
            app.business_name,app.proprietor_name,app.contact_number,app.email,
            app.profile_picture,app.business_permit,app.sanitary_permit,app.gjc_requirements,app.clearance,
            r.rejection_reason,r.rejected_by,r.rejected_at,r.rejected_at AS archived_at,
            CASE WHEN r.reactivated_at IS NULL THEN 0 ELSE 1 END AS reactivated
            FROM stall_application_rejections r JOIN ".gjc_stall_application_source($db)." ON app.id=r.application_id
            WHERE r.reactivated_at IS NULL AND app.status='rejected'
              AND r.id=(SELECT r2.id FROM stall_application_rejections r2 WHERE r2.application_id=r.application_id ORDER BY r2.rejected_at DESC,r2.id DESC LIMIT 1)
            ORDER BY r.rejected_at DESC,r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        archived_json(['success'=>true,'rows'=>$rows]);
    }
    if($action==='reactivate'){
        $eventId=(int)($_POST['id']??0);$actor=gjc_user_id();
        $app=gjc_reactivate_stall_application($db,$eventId,$actor);
        if(!$app)archived_json(['success'=>false,'message'=>'Archived record not found.']);
        try{logAudit($db,$actor,gjc_current_role(),'STALL_UPDATE','stall_applications',
            $app['_reactivation_before'],['id'=>$app['id'],'status'=>'pending_verification','meetup_scheduled_at'=>$app['meetup_scheduled_at']],
            $app['stall_id']??null,['target_id'=>$app['id'],'reason'=>'Reactivated retained application']);}catch(Throwable $auditError){error_log('[admin/api/archived_rejections.php] reactivation audit: '.$auditError->getMessage());}
        // Notification happens after the state transaction; a queue hiccup never loses activation.
        try{
            $mail=gjc_send_stall_meeting_email($app['email'],$app['proprietor_name'],$app['business_name'],new DateTime($app['meetup_scheduled_at']),$app['meetup_location']);
            if($mail['sent'])gjc_stall_application_update($db,(int)$app['id'],['meetup_scheduled_email_sent_at'=>GJC_APPLICATION_DB_NOW]);
        }catch(Throwable $mailError){error_log('[admin/api/archived_rejections.php] meeting notification: '.$mailError->getMessage());}
        archived_json(['success'=>true,'message'=>'Application reactivated for verification.','application_id'=>(int)$app['id'],'meetup_scheduled_at'=>$app['meetup_scheduled_at']]);
    }
    archived_json(['success'=>false,'message'=>'Unknown action.']);
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    error_log('[admin/api/archived_rejections.php] '.$e->getMessage());
    archived_json(['success'=>false,'message'=>'The application could not be reactivated. Nothing was changed.']);
}