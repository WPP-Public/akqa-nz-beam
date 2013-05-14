<?php

namespace Heyday\Component\Beam\Helper;

use Heyday\Component\Beam\Deployment\DeploymentResult;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

class DeploymentResultHelper extends Helper
{
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
     * @param FormatterHelper  $formatter
     * @param OutputInterface  $output
     * @param DeploymentResult $deploymentResult
     */
    public function outputChanges(
        FormatterHelper $formatter,
        OutputInterface $output,
        DeploymentResult $deploymentResult
    ) {
        foreach ($deploymentResult as $change) {
            if ($change['reason'] != array('time')) {
                $output->writeLn(
                    $formatter->formatSection(
                        sprintf(
                            '%s:%s',
                            $change['update'],
                            $change['filetype']
                        ),
                        $formatter->formatSection(
                            implode(',', $change['reason']),
                            $change['filename'],
                            'comment'
                        )
                    )
                );
            }
        }
    }
    /**
     * @param FormatterHelper  $formatter
     * @param OutputInterface  $output
     * @param DeploymentResult $deploymentResult
     */
    public function outputChangesSummary(
        FormatterHelper $formatter,
        OutputInterface $output,
        DeploymentResult $deploymentResult
    ) {
        $totals = array(
            'sent' => 0,
            'received' => 0,
            'created' => 0,
            'deleted' => 0,
            'attributes' => 0
        );

        foreach ($deploymentResult as $change) {
            $totals[$change['update']]++;
        }

        $length = max(array_map('strlen', array_keys($totals))) + 2;

        foreach ($totals as $key => $total) {
            $output->writeLn(
                $formatter->formatSection(
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
