<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\RejectsUnknownFields;
use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\ImageResearchRun;
use App\Models\ResearchSettings;
use App\Services\Research\ResearchClient;
use App\Services\Research\ResearchScheduler;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResearchController extends Controller
{
    use RejectsUnknownFields;

    public function settings(ResearchClient $client)
    {
        $s = ResearchSettings::current();

        return response()->json(['object_research_enabled' => $s->object_research_enabled, 'object_research_provider' => $s->object_research_provider, 'providers' => $client->providers()]);
    }

    public function updateSettings(Request $request, ResearchClient $client)
    {
        $this->rejectUnknownFields($request, ['object_research_enabled', 'object_research_provider'], requireOne: true);
        $data = $request->validate(['object_research_enabled' => ['sometimes', 'boolean'], 'object_research_provider' => ['sometimes', 'required', Rule::in(config('research.providers'))]]);
        ResearchSettings::current()->update($data);

        return $this->settings($client);
    }

    private function image(int $id): Image
    {
        return Image::find($id) ?? throw new ApiException('IMAGE_NOT_FOUND', 404, 'Изображение не найдено.');
    }

    public function history(int $image, ResearchScheduler $scheduler)
    {
        $this->image($image);
        $scheduler->expire($image);

        return response()->json(['data' => ImageResearchRun::where('image_id', $image)->latest('id')->limit(20)->get()->map(fn ($r) => self::present($r))]);
    }

    public function start(Request $request, int $image, ResearchClient $client, ResearchScheduler $scheduler)
    {
        $model = $this->image($image);
        $this->rejectUnknownFields($request, ['question']);
        if ($request->has('question') && $request->input('question') === null) {
            $request->merge(['question' => '']);
        }
        $data = $request->validate(['question' => ['sometimes', 'string', 'max:1000']]);
        if ($model->status !== 'ready' || ! $model->preview_key) {
            throw new ApiException('IMAGE_NOT_RESEARCHABLE', 409, 'Дождитесь готовности превью.');
        }
        $scheduler->expire($image);
        $active = ImageResearchRun::where('image_id', $image)->whereIn('status', ['queued', 'running'])->first();
        if ($active) {
            return response()->json(self::present($active), 202);
        }
        $provider = ResearchSettings::current()->object_research_provider;
        if (! collect($client->providers())->contains(fn ($p) => $p['id'] === $provider && $p['available'])) {
            throw new ApiException('WEB_RESEARCH_UNAVAILABLE', 503, 'Выбранный провайдер недоступен. Проверьте настройки сервиса.');
        }

        return response()->json(self::present($scheduler->schedule($model, 'manual', trim($data['question'] ?? ''))), 202);
    }

    public static function present(ImageResearchRun $r): array
    {
        return ['id' => $r->id, 'image_id' => $r->image_id, 'trigger' => $r->trigger, 'provider' => $r->provider, 'question' => $r->question, 'status' => $r->status, 'result' => $r->result, 'error_code' => $r->error_code, 'created_at' => Api::dateTime($r->created_at), 'finished_at' => Api::dateTime($r->finished_at)];
    }
}
