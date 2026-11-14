<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRM screens. Each page loads its data from the JSON API in routes/api.php,
 * exactly like the old Next.js admin did.
 */
class AdminController extends Controller
{
    private function render(string $component, array $props = []): Response
    {
        $meta = Seo::resolve(['title' => 'Admin CRM', 'noindex' => true]);

        return Inertia::render("Admin/{$component}", [...$props, 'meta' => ['title' => $meta['title']]])
            ->withViewData(['seo' => $meta]);
    }

    public function login(): Response
    {
        return $this->render('Login');
    }

    public function dashboard(): Response
    {
        return $this->render('Dashboard');
    }

    public function leads(): Response
    {
        return $this->render('Leads/Index');
    }

    public function lead(int $id): Response
    {
        return $this->render('Leads/Show', ['id' => $id]);
    }

    public function campaigns(): Response
    {
        return $this->render('Campaigns/Index');
    }

    public function campaign(int $id): Response
    {
        return $this->render('Campaigns/Show', ['id' => $id]);
    }

    public function demos(): Response
    {
        return $this->render('Demos');
    }

    public function followups(): Response
    {
        return $this->render('Followups');
    }

    public function outreach(): Response
    {
        return $this->render('Outreach');
    }

    public function automation(): Response
    {
        return $this->render('Automation');
    }

    public function activities(): Response
    {
        return $this->render('Activities');
    }
}
