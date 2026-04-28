<?php

namespace App\Http\Controllers;

use App\Models\LotofacilAposta;
use App\Models\LotofacilConcurso;
use App\Services\LottusGeradorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

class LottusController extends Controller
{
    public function __construct(
        private readonly LottusGeradorService $geradorService
    ) {
    }

    public function index()
    {
        $contexto = $this->getContextoColeta();
        $apostaHoje = $this->getApostaDeHoje();

        return view('lottus.index', [
            'ultimoConcurso' => $contexto['ultimoConcurso'],
            'proximoConcurso' => $contexto['proximoConcurso'],
            'dataEsperada' => $contexto['dataEsperada'],
            'mostrarModalResultado' => false,
            'apostaDoDia' => $apostaHoje,
            'mostrarAposta' => session()->has('aposta_id'),
        ]);
    }

    public function gerarAposta()
    {
        $contexto = $this->getContextoColeta();

        $apostaHoje = $this->getApostaDeHoje();
        if ($apostaHoje) {
            return redirect()
                ->route('lottus.index')
                ->with('sucesso', 'A aposta de hoje já foi gerada.')
                ->with('aposta_id', $apostaHoje->id);
        }

        if (! $contexto['deveSolicitarNovoConcurso']) {
            return $this->gerarApostaComConcurso($contexto['ultimoConcurso']);
        }

        return view('lottus.index', [
            'ultimoConcurso' => $contexto['ultimoConcurso'],
            'proximoConcurso' => $contexto['proximoConcurso'],
            'dataEsperada' => $contexto['dataEsperada'],
            'mostrarModalResultado' => true,
            'apostaDoDia' => null,
            'mostrarAposta' => false,
        ]);
    }

    public function salvarResultadoEGerar(Request $request)
    {
        $contexto = $this->getContextoColeta();

        $apostaHoje = $this->getApostaDeHoje();
        if ($apostaHoje) {
            return redirect()
                ->route('lottus.index')
                ->with('sucesso', 'A aposta de hoje já foi gerada.')
                ->with('aposta_id', $apostaHoje->id);
        }

        if (! $contexto['deveSolicitarNovoConcurso']) {
            return $this->gerarApostaComConcurso($contexto['ultimoConcurso']);
        }

        $request->validate([
            'data_sorteio' => ['required', 'date_format:Y-m-d', 'unique:lotofacil_concursos,data_sorteio'],
            'bola1' => ['required', 'integer', 'between:1,25'],
            'bola2' => ['required', 'integer', 'between:1,25'],
            'bola3' => ['required', 'integer', 'between:1,25'],
            'bola4' => ['required', 'integer', 'between:1,25'],
            'bola5' => ['required', 'integer', 'between:1,25'],
            'bola6' => ['required', 'integer', 'between:1,25'],
            'bola7' => ['required', 'integer', 'between:1,25'],
            'bola8' => ['required', 'integer', 'between:1,25'],
            'bola9' => ['required', 'integer', 'between:1,25'],
            'bola10' => ['required', 'integer', 'between:1,25'],
            'bola11' => ['required', 'integer', 'between:1,25'],
            'bola12' => ['required', 'integer', 'between:1,25'],
            'bola13' => ['required', 'integer', 'between:1,25'],
            'bola14' => ['required', 'integer', 'between:1,25'],
            'bola15' => ['required', 'integer', 'between:1,25'],
        ]);

        $bolas = [
            (int) $request->bola1,
            (int) $request->bola2,
            (int) $request->bola3,
            (int) $request->bola4,
            (int) $request->bola5,
            (int) $request->bola6,
            (int) $request->bola7,
            (int) $request->bola8,
            (int) $request->bola9,
            (int) $request->bola10,
            (int) $request->bola11,
            (int) $request->bola12,
            (int) $request->bola13,
            (int) $request->bola14,
            (int) $request->bola15,
        ];

        if (count(array_unique($bolas)) !== 15) {
            return back()
                ->withErrors(['bolas' => 'As 15 dezenas devem ser únicas.'])
                ->withInput();
        }

        $concurso = LotofacilConcurso::create([
            'concurso' => $contexto['proximoConcurso'],
            'data_sorteio' => $request->data_sorteio,
            'bola1' => $bolas[0],
            'bola2' => $bolas[1],
            'bola3' => $bolas[2],
            'bola4' => $bolas[3],
            'bola5' => $bolas[4],
            'bola6' => $bolas[5],
            'bola7' => $bolas[6],
            'bola8' => $bolas[7],
            'bola9' => $bolas[8],
            'bola10' => $bolas[9],
            'bola11' => $bolas[10],
            'bola12' => $bolas[11],
            'bola13' => $bolas[12],
            'bola14' => $bolas[13],
            'bola15' => $bolas[14],
            'informado_manualmente' => true,
        ]);

        return $this->gerarApostaComConcurso($concurso);
    }

    private function gerarApostaComConcurso(LotofacilConcurso $concurso)
    {
        $apostaHoje = $this->getApostaDeHoje();

        if ($apostaHoje) {
            return redirect()
                ->route('lottus.index')
                ->with('sucesso', 'A aposta de hoje já foi gerada.')
                ->with('aposta_id', $apostaHoje->id);
        }

        $resultado = $this->geradorService->gerar($concurso);

        $dataReferencia = now()->timezone(config('app.timezone'))->format('Y-m-d');

        $aposta = LotofacilAposta::create([
            'concurso_base_id' => $concurso->id,
            'data_esperada_sorteio' => $dataReferencia,
            'dezenas' => $resultado['dezenas'],
            'score' => $resultado['score'],
            'pares' => $resultado['analise']['pares'],
            'impares' => $resultado['analise']['impares'],
            'soma' => $resultado['analise']['soma'],
            'repetidas_ultimo_concurso' => $resultado['analise']['repetidas_ultimo_concurso'],
            'quentes' => $resultado['analise']['quentes'],
            'atrasadas' => $resultado['analise']['atrasadas'],
            'analise' => $resultado['analise'],
        ]);

        return redirect()
            ->route('lottus.index')
            ->with('sucesso', 'Aposta gerada com sucesso!')
            ->with('aposta_id', $aposta->id);
    }

    private function getContextoColeta(): array
    {
        $agora = now()->timezone(config('app.timezone'));

        $ultimoConcurso = LotofacilConcurso::orderByDesc('concurso')->first();

        if (! $ultimoConcurso) {
            throw new RuntimeException('Nenhum concurso foi encontrado na base.');
        }

        $dataUltimoConcurso = $ultimoConcurso->data_sorteio instanceof Carbon
            ? $ultimoConcurso->data_sorteio->copy()->startOfDay()
            : Carbon::parse($ultimoConcurso->data_sorteio)->startOfDay();

        $proximaDataSorteio = $this->getProximaDataDeSorteio($dataUltimoConcurso);
        $proximoConcurso = (int) $ultimoConcurso->concurso + 1;

        $deveSolicitarNovoConcurso = $this->jaPassouDoHorarioDeColeta($proximaDataSorteio, $agora);

        return [
            'ultimoConcurso' => $ultimoConcurso,
            'proximoConcurso' => $proximoConcurso,
            'dataEsperada' => $proximaDataSorteio,
            'deveSolicitarNovoConcurso' => $deveSolicitarNovoConcurso,
        ];
    }

    private function getProximaDataDeSorteio(Carbon $data): Carbon
    {
        $proxima = $data->copy()->addDay();

        while ($proxima->isSunday()) {
            $proxima->addDay();
        }

        return $proxima->startOfDay();
    }

    private function jaPassouDoHorarioDeColeta(Carbon $dataSorteio, Carbon $agora): bool
    {
        $limite = $dataSorteio->copy()
            ->setTimezone(config('app.timezone'))
            ->setTime(21, 0, 0);

        return $agora->greaterThanOrEqualTo($limite);
    }

    private function getApostaDeHoje(): ?LotofacilAposta
    {
        $apostaId = session('aposta_id');

        if (! $apostaId) {
            return null;
        }

        return LotofacilAposta::where('id', $apostaId)
            ->whereDate('created_at', now()->timezone(config('app.timezone'))->format('Y-m-d'))
            ->latest()
            ->first();
    }
}