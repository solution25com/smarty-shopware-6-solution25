<?php

declare(strict_types=1);

namespace SmartyIntegration\Command;

use SmartyIntegration\Service\SmartyApiService;
use SmartyIntegration\Domain\Address\AdressDto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SmartyTestCommand extends Command
{
    protected static $defaultName = 'smarty:test';
    protected static $defaultDescription = 'Tests SmartyApiService with dummy address data.';

    public function __construct(
        private readonly SmartyApiService $smartyApiService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Smarty API Test');
        $io->section('Sending dummy address to SmartyApiService');

        $addressDto = new AdressDto(
            street: '1600 Amphitheatre Pkwy',
            city: 'Mountain View',
            postalCode: '94043',
            countryIso: 'US'
        );

        $result = $this->smartyApiService->validateAdress($addressDto);

        $io->writeln('Result object:');
        $io->writeln(print_r($result, true));

        $io->success('SmartyApiService test finished.');

        return Command::SUCCESS;
    }
}
