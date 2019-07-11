<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\DeploymentProvider\DeploymentResult;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class DeploymentResultHelper
 * @package Heyday\Beam\Helper
 */
class DeploymentResultHelper extends Helper
{
    /**
     * @var \Symfony\Component\Console\Helper\FormatterHelper
     */
    protected $formatterHelper;

    /**
     * @param FormatterHelper $formatterHelper
     */
    public function __construct(FormatterHelper $formatterHelper)
    {
        $this->formatterHelper = $formatterHelper;
    }

    /**
     * Returns the canonical name of this helper.
     *
     * @return string The canonical name
     *
     * @api
     */
    public function getName()
    {
        return 'deploymentresult';
    }

    /**
     * @param OutputInterface  $output
     * @param DeploymentResult $deploymentResult
     * @param bool             $type
     */
    public function outputChanges(
        OutputInterface $output,
        DeploymentResult $deploymentResult,
        $type = false
    ) {
        $totalNodes = count($deploymentResult->getNestedResults());
        $output->getFormatter()->setStyle('count', new OutputFormatterStyle('cyan'));
        foreach ($deploymentResult as $change) {
            if ($change['reason'] != ['time'] && (!$type || $change['update'] === $type)) {
                // If changes made to multiple servers, show total of modified servers for this line item
                $nodes = isset($change['nodes']) ? $change['nodes'] : 1;
                $nodeCount = $totalNodes > 1
                    ? "<count>{$nodes}/{$totalNodes}</count> "
                    : '';

                // Format
                $message = $this->formatterHelper->formatSection(
                    implode(',', $change['reason']),
                    $nodeCount . $change['filename'],
                    'comment'
                );
                $style = $change['update'] === 'deleted' ? 'error' : 'info';
                $output->writeLn($this->formatterHelper->formatSection($change['update'], $message, $style));

            }
        }
    }

    /**
     * @param OutputInterface  $output
     * @param DeploymentResult $deploymentResult
     */
    public function outputChangesSummary(OutputInterface $output, DeploymentResult $deploymentResult)
    {
        foreach ($deploymentResult->getNestedResults() as $result) {
            $totals = array(
                'sent'       => 0,
                'received'   => 0,
                'created'    => 0,
                'deleted'    => 0,
                'attributes' => 0
            );

            foreach ($result as $change) {
                $totals[$change['update']]++;
            }

            $length = max(array_map('strlen', array_keys($totals))) + 2;

            $output->writeln(
                $this->formatterHelper->formatSection(
                    'summary',
                    '<comment>[host ' . $result->getName() . ']</comment>',
                    'info'
                )
            );

            foreach ($totals as $key => $total) {
                $output->writeLn(
                    $this->formatterHelper->formatSection(
                        'summary',
                        sprintf(
                            '%s%s',
                            str_pad($key . ':', $length, ' '),
                            $total
                        ),
                        'info'
                    )
                );
            }
        }
    }
}
