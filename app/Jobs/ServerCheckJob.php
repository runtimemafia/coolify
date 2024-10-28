<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\InstallLogDrain;
use App\Models\Server;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public $containers;

    public $applications;

    public $databases;

    public $services;

    public $previews;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            if ($this->server->serverStatus() === false) {
                return 'Server is not reachable or not ready.';
            }

            $this->applications = $this->server->applications();
            $this->databases = $this->server->databases();
            $this->services = $this->server->services()->get();
            $this->previews = $this->server->previews();

            if (! $this->server->isSwarmWorker() && ! $this->server->isBuildServer()) {
                ['containers' => $this->containers, 'containerReplicates' => $containerReplicates] = $this->server->getContainers();
                if (is_null($this->containers)) {
                    return 'No containers found.';
                }
                ServerStorageCheckJob::dispatch($this->server);
                GetContainersStatus::run($this->server, $this->containers, $containerReplicates);

                if ($this->server->isSentinelEnabled()) {
                    CheckAndStartSentinelJob::dispatch($this->server);
                }

                if ($this->server->isLogDrainEnabled()) {
                    $this->checkLogDrainContainer();
                }

                if ($this->server->proxySet() && ! $this->server->proxy->force_stop) {
                    $this->server->proxyType();
                    $foundProxyContainer = $this->containers->filter(function ($value, $key) {
                        if ($this->server->isSwarm()) {
                            return data_get($value, 'Spec.Name') === 'coolify-proxy_traefik';
                        } else {
                            return data_get($value, 'Name') === '/coolify-proxy';
                        }
                    })->first();
                    if (! $foundProxyContainer) {
                        try {
                            $shouldStart = CheckProxy::run($this->server);
                            if ($shouldStart) {
                                StartProxy::run($this->server, false);
                                $this->server->team?->notify(new ContainerRestarted('coolify-proxy', $this->server));
                            }
                        } catch (\Throwable $e) {
                        }
                    } else {
                        $this->server->proxy->status = data_get($foundProxyContainer, 'State.Status');
                        $this->server->save();
                        $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
                        instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
                    }
                }
            }

        } catch (\Throwable $e) {
            return handleError($e);
        }

    }

    private function checkLogDrainContainer()
    {
        $foundLogDrainContainer = $this->containers->filter(function ($value, $key) {
            return data_get($value, 'Name') === '/coolify-log-drain';
        })->first();
        if ($foundLogDrainContainer) {
            $status = data_get($foundLogDrainContainer, 'State.Status');
            if ($status !== 'running') {
                InstallLogDrain::dispatch($this->server);
            }
        } else {
            InstallLogDrain::dispatch($this->server);
        }
    }
}
