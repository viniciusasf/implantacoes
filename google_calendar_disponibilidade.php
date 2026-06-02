<?php
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google_oauth_token_helper.php';

$diasSemana = ['1' => 'Segunda-Feira', '2' => 'Terça-Feira', '3' => 'Quarta-Feira', '4' => 'Quinta-Feira', '5' => 'Sexta-Feira', '6' => 'Sábado', '7' => 'Domingo'];

function returnResponse($success, $message, $data = [])
{
    die(json_encode(array_merge(['success' => $success, 'message' => $message], $data)));
}

function hasOverlap(DateTime $start, DateTime $end, array $busyIntervals)
{
    foreach ($busyIntervals as $interval) {
        if ($start < $interval['end'] && $end > $interval['start']) {
            return true;
        }
    }
    return false;
}

try {
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $dias = isset($_GET['dias']) ? (int)$_GET['dias'] : 3;
    if ($dias < 1) {
        $dias = 1;
    } elseif ($dias > 14) {
        $dias = 14;
    }

    $duracaoMin = 60;

    $agora = new DateTime('now', $timezone);
    $windowStart = clone $agora;
    $windowEnd = (clone $agora)->modify('+' . $dias . ' days')->setTime(23, 59, 59);

    $client = new Google\Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->addScope(Google\Service\Calendar::CALENDAR_READONLY);
    $client->setAccessType('offline');

    $tokenPath = __DIR__ . '/token.json';
    if (!file_exists($tokenPath)) {
        returnResponse(false, 'Token Google nao encontrado. Sincronize um evento primeiro.');
    }

    $tokenData = json_decode((string)file_get_contents($tokenPath), true);
    if (!is_array($tokenData)) {
        returnResponse(false, 'Token Google invalido.');
    }
    $client->setAccessToken($tokenData);

    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if (!$refreshToken) {
            returnResponse(false, 'Token expirado sem refresh token. Sincronize novamente.');
        }
        $novoToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($novoToken['error'])) {
            if (googleIsInvalidGrantError($novoToken['error'])) {
                googleForgetToken();
                returnResponse(false, 'Token Google foi revogado ou é inválido. Por favor, reautorize o acesso no menu lateral.');
            }
            returnResponse(false, 'Falha ao renovar token Google: ' . $novoToken['error']);
        }
        googlePersistToken($client);
    }

    $service = new Google\Service\Calendar($client);
    $freeBusyRequest = new Google\Service\Calendar\FreeBusyRequest();
    $freeBusyRequest->setTimeMin($windowStart->format(DateTime::RFC3339));
    $freeBusyRequest->setTimeMax($windowEnd->format(DateTime::RFC3339));
    $freeBusyRequest->setTimeZone('America/Sao_Paulo');
    $freeBusyRequest->setItems([['id' => 'primary']]);
    $freeBusy = $service->freebusy->query($freeBusyRequest);

    $busyIntervals = [];
    $calendars = $freeBusy->getCalendars();
    if (isset($calendars['primary'])) {
        foreach ((array)$calendars['primary']->getBusy() as $busy) {
            $busyStart = new DateTime((string)$busy->getStart());
            $busyEnd = new DateTime((string)$busy->getEnd());
            $busyStart->setTimezone($timezone);
            $busyEnd->setTimezone($timezone);
            $busyIntervals[] = ['start' => $busyStart, 'end' => $busyEnd];
        }
    }

    usort($busyIntervals, function ($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    // Identificar feriados
    $holidays = [];
    try {
        $calendarList = $service->calendarList->listCalendarList();
        $holidayCalendarId = null;
        foreach ($calendarList->getItems() as $cal) {
            if (strpos(strtolower($cal->getSummary()), 'feriado') !== false) {
                $holidayCalendarId = $cal->getId();
                break;
            }
        }

        if ($holidayCalendarId) {
            $events = $service->events->listEvents($holidayCalendarId, [
                'timeMin' => $windowStart->format(DateTime::RFC3339),
                'timeMax' => $windowEnd->format(DateTime::RFC3339),
            ]);
            foreach ($events->getItems() as $event) {
                if ($event->getStart() && $event->getStart()->getDate()) {
                    $holidays[] = $event->getStart()->getDate(); // Y-m-d
                } else if ($event->getStart() && $event->getStart()->getDateTime()) {
                    $dt = new DateTime($event->getStart()->getDateTime());
                    $holidays[] = $dt->format('Y-m-d');
                }
            }
        }

        // Também verificar eventos de dia inteiro no calendário principal que contenham "feriado" no título
        $primaryEvents = $service->events->listEvents('primary', [
            'timeMin' => $windowStart->format(DateTime::RFC3339),
            'timeMax' => $windowEnd->format(DateTime::RFC3339),
        ]);
        foreach ($primaryEvents->getItems() as $event) {
            if ($event->getStart() && $event->getStart()->getDate()) { // Evento de dia inteiro
                if (strpos(strtolower((string)$event->getSummary()), 'feriado') !== false) {
                    $holidays[] = $event->getStart()->getDate();
                }
            }
        }
    } catch (Throwable $e) {
        // Ignorar erros ao buscar feriados para não quebrar a funcionalidade principal
    }

    $diasDisponiveis = [];
    for ($offset = 0; $offset <= $dias; $offset++) {
        $dia = (clone $agora)->modify('+' . $offset . ' days');
        $diaDataStr = $dia->format('Y-m-d');
        
        $diaSemana = (int)$dia->format('N');
        
        // Se for fim de semana OU for feriado
        if ($diaSemana > 5 || in_array($diaDataStr, $holidays)) {
            $diasDisponiveis[] = [
                'data' => $dia->format('Y-m-d'),
                'data_label' => $dia->format('d/m') . ' ' . ($diasSemana[$dia->format('N')] ?? ''),
                'horarios' => [],
            ];
            continue;
        }

        $inicioDia = (clone $dia)->setTime(8, 30, 0);
        $fimDia = (clone $dia)->setTime(17, 30, 0);

        if ((int)$dia->format('Ymd') === (int)$agora->format('Ymd')) {
            if ($agora > $inicioDia) {
                $inicioDia = clone $agora;
                $minutos = (int)$inicioDia->format('i');
                $resto = $minutos % 30;
                if ($resto > 0) {
                    $inicioDia->modify('+' . (30 - $resto) . ' minutes');
                }
                $inicioDia->setTime((int)$inicioDia->format('H'), (int)$inicioDia->format('i'), 0);
            }
        }

        $horarios = [];
        $cursor = clone $inicioDia;
        $inicioAlmoco = (clone $dia)->setTime(12, 30, 0);
        $fimAlmoco = (clone $dia)->setTime(13, 30, 0);
        while ($cursor < $fimDia) {
            $slotEnd = (clone $cursor)->modify('+' . $duracaoMin . ' minutes');
            if ($slotEnd > $fimDia) {
                break;
            }
            $conflitaComAlmoco = ($cursor < $fimAlmoco && $slotEnd > $inicioAlmoco);
            if (!$conflitaComAlmoco && !hasOverlap($cursor, $slotEnd, $busyIntervals)) {
                $horarios[] = [
                    'datetime_local' => $cursor->format('Y-m-d\TH:i'),
                    'hora' => $cursor->format('H:i'),
                ];
            }
            $cursor->modify('+30 minutes');
        }

        $diasDisponiveis[] = [
            'data' => $dia->format('Y-m-d'),
            'data_label' => $dia->format('d/m') . ' ' . ($diasSemana[$dia->format('N')] ?? ''),
            'horarios' => $horarios,
        ];
    }

    returnResponse(true, 'Disponibilidade carregada.', [
        'periodo' => [
            'inicio' => $windowStart->format('Y-m-d H:i:s'),
            'fim' => $windowEnd->format('Y-m-d H:i:s'),
            'dias' => $dias,
            'duracao_min' => $duracaoMin,
        ],
        'dias_disponiveis' => $diasDisponiveis,
    ]);
} catch (Throwable $e) {
    returnResponse(false, 'Erro ao consultar disponibilidade: ' . $e->getMessage());
}
