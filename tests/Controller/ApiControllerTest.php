<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Class ApiControllerTest
 * @package App\Tests\Controller
 */
#[Group('api')]
class ApiControllerTest extends WebTestCase
{
    public function testGetCard()
    {
        $client = static::createClient();
        $client->request('GET', '/api/public/card/1_50');
        $response = $client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey("code", $data);
        $this->assertEquals("1_50", $data['code']);
    }

    public function testListCards()
    {
        $client = static::createClient();
        $client->request('GET', '/api/public/cards/');
        $response = $client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testListCardsByPack()
    {
        $client = static::createClient();
        $client->request('GET', '/api/public/cards/1');
        $response = $client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey("pack_code", $item);
            $this->assertEquals("1", $item['pack_code']);
        }
    }

    public function testListPacks()
    {
        $client = static::createClient();
        $client->request('GET', '/api/public/packs/');
        $response = $client->getResponse();
        $json = $response->getContent();
        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testGetDecklist()
    {
        $client = static::createClient();
        $client->request('GET', '/api/public/decklist/1');
        $response = $client->getResponse();
        if ($response->getStatusCode() === 404) {
            $this->markTestSkipped('No decklist 1 in this database');
        }
        $json = $response->getContent();
        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
        $this->assertArrayHasKey("id", $data);
        $this->assertEquals(1, $data['id']);
    }

    public function testListFormats()
    {
        $client = static::createClient();
        $client->request('GET', '/api/public/formats/');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        foreach ($data as $format) {
            $this->assertArrayHasKey('code', $format);
            $this->assertNotSame('debug', $format['code']);
        }
    }
}
