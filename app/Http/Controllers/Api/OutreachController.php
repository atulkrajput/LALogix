<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AutomationRule;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\WhatsappLog;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * E-mail templates, automation rules and outreach (email / WhatsApp).
 * Ported from old/src/app/api/{email-templates,automation,outreach}.
 */
class OutreachController extends Controller
{
    // ---- Email templates -------------------------------------------------

    public function templates(): JsonResponse
    {
        return response()->json(['templates' => EmailTemplate::orderByDesc('created_at')->get()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        if (! $request->input('name') || ! $request->input('subject') || ! $request->input('body')) {
            return response()->json(['error' => 'name, subject, and body are required'], 400);
        }

        $template = EmailTemplate::create($request->only(['name', 'subject', 'body']));

        return response()->json($template->fresh(), 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);
        $template->update(array_filter($request->only(['name', 'subject', 'body'])));

        return response()->json($template->fresh());
    }

    public function destroyTemplate(int $id): JsonResponse
    {
        EmailTemplate::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ---- Automation rules ------------------------------------------------

    public function rules(): JsonResponse
    {
        return response()->json(['rules' => AutomationRule::orderByDesc('created_at')->get()]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        if (! $request->input('name') || ! $request->input('trigger') || ! $request->input('action')) {
            return response()->json(['error' => 'name, trigger, and action are required'], 400);
        }

        $rule = AutomationRule::create([
            'name' => $request->input('name'),
            'trigger' => $request->input('trigger'),
            'action' => $request->input('action'),
            'template_id' => $request->input('template_id') ? (int) $request->input('template_id') : null,
            'enabled' => $request->input('enabled') !== false,
        ]);

        return response()->json($rule->fresh(), 201);
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);
        $data = array_filter($request->only(['name', 'trigger', 'action']));

        if ($request->exists('template_id')) {
            $data['template_id'] = $request->input('template_id') ? (int) $request->input('template_id') : null;
        }
        if ($request->exists('enabled')) {
            $data['enabled'] = (bool) $request->input('enabled');
        }

        $rule->update($data);

        return response()->json($rule->fresh());
    }

    public function destroyRule(int $id): JsonResponse
    {
        AutomationRule::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ---- Outreach --------------------------------------------------------

    /** POST /api/outreach/email */
    public function email(Request $request, EmailService $email): JsonResponse
    {
        $leadIds = $request->input('lead_ids');
        if (! is_array($leadIds) || count($leadIds) === 0) {
            return response()->json(['error' => 'lead_ids array is required'], 400);
        }

        $subject = $request->input('subject');
        $body = $request->input('body');

        if ($templateId = $request->input('template_id')) {
            $template = EmailTemplate::find((int) $templateId);
            if (! $template) {
                return response()->json(['error' => 'Template not found'], 404);
            }
            $subject = $template->subject;
            $body = $template->body;
        }

        if (! $subject || ! $body) {
            return response()->json(['error' => 'subject and body are required (or provide template_id)'], 400);
        }

        $sent = 0;
        $failed = 0;

        foreach (Lead::whereIn('id', array_map('intval', $leadIds))->get() as $lead) {
            $personalSubject = EmailService::personalize($subject, $lead->name, $lead->organization);
            $personalBody = EmailService::personalize($body, $lead->name, $lead->organization);

            $result = $email->send($lead->email, $personalSubject, $personalBody);

            if ($result['success']) {
                $sent++;
                Activity::create(['lead_id' => $lead->id, 'type' => 'email', 'notes' => "Email sent: {$personalSubject}"]);
                $lead->increment('score', 2);
            } else {
                $failed++;
            }
        }

        return response()->json(['success' => true, 'sent' => $sent, 'failed' => $failed]);
    }

    /** POST /api/outreach/whatsapp — logs the message (no WhatsApp API wired yet, same as before). */
    public function whatsapp(Request $request): JsonResponse
    {
        $message = $request->input('message');
        if (! $message) {
            return response()->json(['error' => 'message is required'], 400);
        }

        $ids = $request->input('lead_ids') ?: ($request->input('lead_id') ? [$request->input('lead_id')] : []);
        if (count($ids) === 0) {
            return response()->json(['error' => 'lead_id or lead_ids is required'], 400);
        }

        $sent = 0;
        foreach ($ids as $id) {
            $lead = Lead::find((int) $id);
            if (! $lead) {
                continue;
            }

            $personal = EmailService::personalize($message, $lead->name, $lead->organization);

            WhatsappLog::create(['lead_id' => $lead->id, 'message' => $personal, 'status' => 'sent']);
            Activity::create(['lead_id' => $lead->id, 'type' => 'whatsapp', 'notes' => 'WhatsApp: '.Str::substr($personal, 0, 100)]);

            $sent++;
        }

        return response()->json(['success' => true, 'sent' => $sent]);
    }

    /** GET /api/outreach/whatsapp */
    public function whatsappLogs(Request $request): JsonResponse
    {
        $query = WhatsappLog::with('lead:id,name,phone')->orderByDesc('created_at')->limit(100);

        if ($leadId = $request->query('lead_id')) {
            $query->where('lead_id', (int) $leadId);
        }

        return response()->json(['logs' => $query->get()]);
    }
}
