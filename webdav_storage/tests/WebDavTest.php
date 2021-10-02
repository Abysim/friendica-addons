<?php

namespace Friendica\Addon\webdav_storage\tests;

use Friendica\Addon\webdav_storage\src\WebDav;
use Friendica\Core\Config\IConfig;
use Friendica\DI;
use Friendica\Model\Storage\IWritableStorage;
use Friendica\Test\src\Model\Storage\StorageTest;

class WebDavTest extends StorageTest
{
	public function dataMultiStatus()
	{
		return [
			'nextcloud' => [
				'xml' => <<<EOF
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns"
			   xmlns:nc="http://nextcloud.org/ns">
	<d:response>
		<d:href>/remote.php/dav/files/admin/Friendica_test/97/18/</d:href>
		<d:propstat>
			<d:prop>
				<d:getlastmodified>Mon, 30 Aug 2021 12:58:54 GMT</d:getlastmodified>
				<d:resourcetype>
					<d:collection/>
				</d:resourcetype>
				<d:quota-used-bytes>45017</d:quota-used-bytes>
				<d:quota-available-bytes>59180834349</d:quota-available-bytes>
				<d:getetag>&quot;612cd60ec9fd5&quot;</d:getetag>
			</d:prop>
			<d:status>HTTP/1.1 200 OK</d:status>
		</d:propstat>
	</d:response>
	<d:response>
		<d:href>
			/remote.php/dav/files/admin/Friendica_test/97/18/4d9d36f614dc005756bdfb9abbf1d8d24aa9ae842e5d6b5e7eb1dafbe767
															  </d:href>
		<d:propstat>
			<d:prop>
				<d:getlastmodified>Mon, 30 Aug 2021 12:58:54 GMT</d:getlastmodified>
				<d:getcontentlength>45017</d:getcontentlength>
				<d:resourcetype/>
				<d:getetag>&quot;4f7a144092532141d0e6b925e50a896e&quot;</d:getetag>
				<d:getcontenttype>application/octet-stream
				</d:getcontenttype>
			</d:prop>
			<d:status>HTTP/1.1 200 OK</d:status>
		</d:propstat>
		<d:propstat>
			<d:prop>
				<d:quota-used-bytes/>
				<d:quota-available-bytes/>
			</d:prop>
			<d:status>HTTP/1.1 404 Not Found
									   </d:status>
		</d:propstat>
	</d:response>
</d:multistatus>
EOF,
				'assertionCount' => 2,
			],
			'onlyDir' => [
				'xml' => <<<EOF
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/files/admin/Friendica_test/34/cf/</d:href>
    <d:propstat>
      <d:prop>
        <d:getlastmodified>Sun, 05 Sep 2021 17:56:05 GMT</d:getlastmodified>
        <d:resourcetype>
          <d:collection/>
        </d:resourcetype>
        <d:quota-used-bytes>0</d:quota-used-bytes>
        <d:quota-available-bytes>59182800697</d:quota-available-bytes>
        <d:getetag>"613504b55db4f"</d:getetag>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
EOF,
				'assertionCount' => 1,
			],
		];
	}

	/**
	 * @dataProvider dataMultiStatus
	 */
	public function testMultistatus(string $xml, int $assertionCount)
	{
		$responseDoc = new \DOMDocument();
		$responseDoc->loadXML($xml);

		$xpath = new \DOMXPath($responseDoc);
		$xpath->registerNamespace('d', 'DAV');

		self::assertCount($assertionCount, $xpath->query('//d:multistatus/d:response'));
	}

	protected function getInstance()
	{
		$config = \Mockery::mock(IConfig::class);
		$config->shouldReceive('get')->with('webdav', 'username')->andReturn(getenv('WEBDAV_USERNAME'));
		$config->shouldReceive('get')->with('webdav', 'password', '')->andReturn(getenv('WEBDAV_PASSWORD'));
		$config->shouldReceive('get')->with('webdav', 'url')->andReturn(getenv('WEBDAV_URL'));
		$config->shouldReceive('get')->with('webdav', 'auth_type', 'basic')->andReturn('basic');

		return new WebDav(DI::l10n(), $config, DI::httpClient(), DI::logger());
	}

	protected function assertOption(IWritableStorage $storage)
	{
		self::assertCount(1, ['1']);
	}
}
