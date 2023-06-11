<?php
namespace TurboLabIt\ShopifySdk\tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;


abstract class Base extends KernelTestCase
{
    protected string $serviceId = '';

    protected function getInstance()
    {
        self::bootKernel();
        $container = static::getContainer();
        $connector = $container->get($this->serviceId);
        return $connector;
    }
}
