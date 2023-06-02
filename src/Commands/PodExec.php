<?php

namespace App\Commands;

use App\Exception\NoSuitablePodException;
use DateTimeInterface;
use RenokiCo\PhpK8s\Exceptions\KubeConfigClusterNotFound;
use RenokiCo\PhpK8s\Exceptions\KubeConfigContextNotFound;
use RenokiCo\PhpK8s\Exceptions\KubeConfigUserNotFound;
use RenokiCo\PhpK8s\Kinds\K8sPod;
use RenokiCo\PhpK8s\KubernetesCluster;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PodExec extends Command {
    public const COMMAND_NAME = 'pod-exec';
    public const ARG_POD_LABEL_SELECTORS = 'pod-label-selector';
    public const ARG_POD_CONTAINER = 'pod-container';
    public const ARG_COMMAND = 'exec-command';
    public const ARG_NAMESPACE = 'namespace';
    public const OPT_DRYRUN = 'dry-run';
    public const K8S_TOKEN_FILE = '/var/run/secrets/kubernetes.io/serviceaccount/token';
    public const K8S_CA_CERT_FILE = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
    private static string $stdOutFile = '/tmp/stdout.txt';
    private static string $stdErrFile = '/tmp/stderr.txt';

    protected function configure(): void
    {
        $this
            ->setName(self::COMMAND_NAME)
            ->addArgument(self::ARG_NAMESPACE, InputArgument::REQUIRED, 'Namespace')
            ->addArgument(self::ARG_POD_LABEL_SELECTORS, InputArgument::REQUIRED, 'Pod label selector, comma separated')
            ->addArgument(self::ARG_POD_CONTAINER, InputArgument::REQUIRED, 'The container to run on')
            ->addArgument(self::ARG_COMMAND, InputArgument::REQUIRED|InputArgument::IS_ARRAY, 'Command')
            ->addOption(self::OPT_DRYRUN, null, InputOption::VALUE_NONE, 'Dry run only');
    }

    /**
     * @throws KubeConfigContextNotFound
     * @throws KubeConfigUserNotFound
     * @throws NoSuitablePodException
     * @throws KubeConfigClusterNotFound
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (is_string($inputNamespace = $input->getArgument(self::ARG_NAMESPACE))) {
            $namespace = $inputNamespace;
        } else {
            throw new \RuntimeException('Invalid namespace');
        }

        if (is_string($inputPodContainer = $input->getArgument(self::ARG_POD_CONTAINER))) {
            $container = $inputPodContainer;
        } else {
            throw new \RuntimeException('Invalid pod container');
        }

        $podLabelSelectors = [];
        if (is_string($podLabelSelectorsInput = $input->getArgument(self::ARG_POD_LABEL_SELECTORS))) {
            $selectors = explode(',', $podLabelSelectorsInput);
            array_walk($selectors, static function ($keyValuePair) use (&$podLabelSelectors) {
                $parts = explode('=', $keyValuePair);

                if (2 !== count($parts)) {
                    throw new \RuntimeException(sprintf('Invalid label selector: %s', $keyValuePair));
                }

                $podLabelSelectors[] = [
                    'key' => $parts[0],
                    'value' => $parts[1],
                ];
            });
        }

        if (is_readable(self::K8S_TOKEN_FILE)) {
            $cluster = (new KubernetesCluster(sprintf('https://%s:%s', $_SERVER['KUBERNETES_SERVICE_HOST'], $_SERVER['KUBERNETES_SERVICE_PORT'])))
                ->loadTokenFromFile(self::K8S_TOKEN_FILE)->withCaCertificate(self::K8S_CA_CERT_FILE);
        } else {
            $cluster = KubernetesCluster::fromKubeConfigVariable();
        }

        $runnerPod = null;
        $failCount = 0;
        while (null === $runnerPod) {
            try {
                $runnerPod = $this->selectHostPod($cluster, $namespace, $podLabelSelectors);
            } catch (NoSuitablePodException $e) {
                $output->writeln('Unable to find a suitable pod to run on. Backing off for a further 10 seconds.');
                sleep(10);
                if ($failCount++ > 18) {
                    throw new NoSuitablePodException();
                }
            }
        }

        $command = $input->getArgument(self::ARG_COMMAND);

        if ($input->getOption(self::OPT_DRYRUN)) {
            (new Table($output))->addRow([sprintf('<error>DRY RUN</error> <info>Would have run the following command on pod: %s</>', $runnerPod->getName())])->render();
            $output->writeln(sprintf('<comment>%s</comment>', implode(' ', $command)));
        } else {
            $output->write(sprintf('<comment>[%s] Running command: %s</comment>', (new \DateTimeImmutable())->format(DateTimeInterface::ATOM), implode(' ', $command)));
            $execStartTime = new \DateTimeImmutable();
            $resultCode = $this->execOnPod($runnerPod, $container, implode(' ', $command));
            $output->writeln(sprintf('..done (%s seconds)', (new \DateTimeImmutable())->diff($execStartTime)->s));

            (new Table($output))->addRow([sprintf('<info>[%s] Output for pod: %s</>', (new \DateTimeImmutable())->format(DateTimeInterface::ATOM), $runnerPod->getName())])->render();

            if (0 !== $resultCode) {
                $output->writeln(sprintf('<error>Command returned a non-zero status code: %s</error>', $resultCode));
            }

            if (is_readable(self::$stdOutFile)) {
                $output->writeln(sprintf(PHP_EOL . '<comment>Std Output</comment>'));
                $output->writeln(sprintf('<comment>----------</comment>'));
                readfile(self::$stdOutFile);
            }

            if (is_readable(self::$stdErrFile)) {
                $output->writeln(sprintf(PHP_EOL . '<info>Std Error</info>'));
                $output->writeln(sprintf('<info>---------</info>'));
                readfile(self::$stdErrFile);
            }

            return $resultCode;
        }

        return 0;
    }

    /**
     * @param KubernetesCluster $cluster
     * @param string            $namespace
     * @param array<int, mixed> $podLabelSelectors
     * @return K8sPod
     * @throws NoSuitablePodException
     */
    private function selectHostPod(KubernetesCluster $cluster, string $namespace, array $podLabelSelectors = []): K8sPod
    {
        $pods = $cluster->getAllPods($namespace)
            ->filter(static function(K8sPod $pod) use ($podLabelSelectors) {
                foreach ($podLabelSelectors as $eachLabelSelector) {
                    if ($eachLabelSelector['value'] !== $pod->getLabel($eachLabelSelector['key'])) {
                        return false;
                    }
                }

                return $pod->getPhase() === 'Running';
            });

        if ($pods->count() <= 0) {
            throw new NoSuitablePodException();
        }

        return $pods->random();
    }

    private function execOnPod(K8sPod $pod, string $container, string $command): int
    {
        $command = sprintf('kubectl -n %s exec -i %s -c %s -- %s', $pod->getNamespace(), $pod->getName(), $container, $command);

        $descriptorSpec = [
            ['pipe', 'r'],
            ['file', self::$stdOutFile, 'w'],
            ['file', self::$stdErrFile, 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        fclose($pipes[0]);

        return proc_close($process);
    }
}