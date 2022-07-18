<?php

namespace MediaWiki\Tests\Rest\Handler;

use Exception;
use MediaWiki\MainConfigNames;
use MediaWiki\MainConfigSchema;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Rest\Handler\ParsoidFormatHelper;
use MediaWiki\Rest\Handler\ParsoidHandler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\ResponseFactory;
use MediaWiki\Tests\Rest\RestTestTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;
use Wikimedia\Parsoid\Config\DataAccess;
use Wikimedia\Parsoid\Config\PageConfig;
use Wikimedia\Parsoid\Config\PageConfigFactory;
use Wikimedia\Parsoid\Config\SiteConfig;
use Wikimedia\Parsoid\Core\ClientError;
use Wikimedia\Parsoid\Core\ResourceLimitExceededException;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\Parsoid;

/**
 * @group Database
 */
class ParsoidHandlerTest extends MediaWikiIntegrationTestCase {
	use RestTestTrait;

	/**
	 * Default request attributes, see ParsoidHandler::getRequestAttributes()
	 */
	private const DEFAULT_ATTRIBS = [
		'titleMissing' => false,
		'pageName' => '',
		'oldid' => null,
		'body_only' => true,
		'errorEnc' => 'plain',
		'iwp' => 'exwiki',
		'subst' => null,
		'offsetType' => 'byte',
		'pagelanguage' => 'en',
		'opts' => [],
		'envOptions' => [
			'prefix' => 'exwiki',
			'domain' => 'wiki.example.com',
			'pageName' => '',
			'offsetType' => 'byte',
			'cookie' => '',
			'reqId' => 'test+test+test',
			'userAgent' => 'UTAgent',
			'htmlVariantLanguage' => null,
			'outputContentVersion' => Parsoid::AVAILABLE_VERSIONS[0],
		],
	];

	private function createRouter( $authority, $request ) {
		return $this->newRouter( [
			'authority' => $authority,
			'request' => $request,
		] );
	}

	private function newParsoidHandler( $methodOverrides = [] ): ParsoidHandler {
		$parsoidSettings = [];
		$method = 'POST';

		$parsoidSettings += MainConfigSchema::getDefaultValue( MainConfigNames::ParsoidSettings );

		$dataAccess = $this->getServiceContainer()->getParsoidDataAccess();
		$siteConfig = $this->getServiceContainer()->getParsoidSiteConfig();
		$pageConfigFactory = $this->getServiceContainer()->getParsoidPageConfigFactory();

		$handler = new class (
			$parsoidSettings,
			$siteConfig,
			$pageConfigFactory,
			$dataAccess,
			$methodOverrides
		) extends ParsoidHandler {
			private $overrides;

			public function __construct(
				array $parsoidSettings,
				SiteConfig $siteConfig,
				PageConfigFactory $pageConfigFactory,
				DataAccess $dataAccess,
				array $overrides
			) {
				parent::__construct(
					$parsoidSettings,
					$siteConfig,
					$pageConfigFactory,
					$dataAccess
				);

				$this->overrides = $overrides;
			}

			protected function parseHTML( string $html, bool $validateXMLNames = false ): Document {
				if ( isset( $this->overrides['parseHTML'] ) ) {
					return $this->overrides['parseHTML']( $html, $validateXMLNames );
				}

				return parent::parseHTML(
					$html,
					$validateXMLNames
				); // TODO: Change the autogenerated stub
			}

			protected function newParsoid(): Parsoid {
				if ( isset( $this->overrides['newParsoid'] ) ) {
					return $this->overrides['newParsoid']();
				}

				return parent::newParsoid(); // TODO: Change the autogenerated stub
			}

			public function execute(): Response {
				ParsoidHandlerTest::fail( 'execute was not expected to be called' );
			}

			public function &getRequestAttributes(): array {
				if ( isset( $this->overrides['getRequestAttributes'] ) ) {
					return $this->overrides['getRequestAttributes']();
				}

				return parent::getRequestAttributes();
			}

			public function acceptable( array &$attribs ): bool {
				if ( isset( $this->overrides['acceptable'] ) ) {
					return $this->overrides['acceptable']( $attribs );
				}

				return parent::acceptable( $attribs );
			}

			public function createPageConfig(
				string $title,
				?int $revision,
				?string $wikitextOverride = null,
				?string $pagelanguageOverride = null
			): PageConfig {
				if ( isset( $this->overrides['createPageConfig'] ) ) {
					return $this->overrides['createPageConfig'](
						$title, $revision, $wikitextOverride, $pagelanguageOverride
					);
				}

				return parent::createPageConfig(
					$title,
					$revision,
					$wikitextOverride,
					$pagelanguageOverride
				);
			}

			public function createRedirectResponse(
				string $path,
				array $pathParams = [],
				array $queryParams = []
			): Response {
				return parent::createRedirectResponse(
					$path,
					$pathParams,
					$queryParams
				);
			}

			public function createRedirectToOldidResponse(
				PageConfig $pageConfig,
				array $attribs
			): Response {
				return parent::createRedirectToOldidResponse(
					$pageConfig,
					$attribs
				);
			}

			public function wt2html(
				PageConfig $pageConfig,
				array $attribs,
				?string $wikitext = null
			) {
				return parent::wt2html(
					$pageConfig,
					$attribs,
					$wikitext
				);
			}

			public function html2wt( PageConfig $pageConfig, array $attribs, string $html ) {
				return parent::html2wt(
					$pageConfig,
					$attribs,
					$html
				);
			}

			public function pb2pb( array $attribs ) {
				return parent::pb2pb( $attribs );
			}

			public function updateRedLinks(
				PageConfig $pageConfig,
				array $attribs,
				array $revision
			) {
				return parent::updateRedLinks(
					$pageConfig,
					$attribs,
					$revision
				);
			}

			public function languageConversion(
				PageConfig $pageConfig,
				array $attribs,
				array $revision
			) {
				return parent::languageConversion(
					$pageConfig,
					$attribs,
					$revision
				);
			}
		};

		$authority = new UltimateAuthority( new UserIdentityValue( 0, '127.0.0.1' ) );
		$request = new RequestData( [ 'method' => $method ] );
		$router = $this->createRouter( $authority, $request );
		$config = [];

		$formatter = $this->createMock( ITextFormatter::class );
		$formatter->method( 'format' )->willReturnCallback( static function ( MessageValue $msg ) {
			return $msg->dump();
		} );

		/** @var ResponseFactory|MockObject $responseFactory */
		$responseFactory = new ResponseFactory( [ 'qqx' => $formatter ] );

		$handler->init(
			$router,
			$request,
			$config,
			$authority,
			$responseFactory,
			$this->createHookContainer(),
			$this->getSession()
		);

		return $handler;
	}

	private function getPageConfig( PageIdentity $page ): PageConfig {
		return $this->getServiceContainer()->getParsoidPageConfigFactory()->create( $page );
	}

	private function getTextFromFile( string $name ): string {
		return trim( file_get_contents( __DIR__ . "/data/Transform/$name" ) );
	}

	private function getJsonFromFile( string $name ): array {
		$text = $this->getTextFromFile( $name );
		return json_decode( $text, JSON_OBJECT_AS_ARRAY );
	}

	public function provideHtml2wt() {
		$profileVersion = '2.4.0';
		$wikitextProfileUri = 'https://www.mediawiki.org/wiki/Specs/wikitext/1.0.0';
		$htmlProfileUri = 'https://www.mediawiki.org/wiki/Specs/HTML/' . $profileVersion;
		$dataParsoidProfileUri = 'https://www.mediawiki.org/wiki/Specs/data-parsoid/' . $profileVersion;

		$wikiTextContentType = "text/plain; charset=utf-8; profile=\"$wikitextProfileUri\"";
		$htmlContentType = "text/html;profile=\"$htmlProfileUri\"";
		$dataParsoidContentType = "application/json;profile=\"$dataParsoidProfileUri\"";

		$htmlHeaders = [
			'content-type' => $htmlContentType,
		];

		// NOTE: profile version 999 is a placeholder for a future feature, see T78676
		$htmlContentType999 = 'text/html;profile="https://www.mediawiki.org/wiki/Specs/HTML/999.0.0"';
		$htmlHeaders999 = [
			'content-type' => $htmlContentType999,
		];

		// should convert html to wikitext ///////////////////////////////////
		$html = $this->getTextFromFile( 'MainPage-data-parsoid.html' );
		$expectedText = [
			'MediaWiki has been successfully installed',
			'== Getting started ==',
		];

		$attribs = [];
		yield 'should convert html to wikitext' => [
			$attribs,
			$html,
			$expectedText,
		];

		// should load original wikitext by revision id ////////////////////
		$attribs = [
			'oldid' => 1, // will be replaced by the actual revid
		];
		yield 'should load original wikitext by revision id' => [
			$attribs,
			$html,
			$expectedText,
		];

		// should accept original wikitext in body ////////////////////
		$originalWikitext = $this->getTextFromFile( 'OriginalMainPage.wikitext' );
		$attribs = [
			'opts' => [
				'original' => [
					'wikitext' => [
						'headers' => [
							'content-type' => $wikiTextContentType,
						],
						'body' => $originalWikitext,
					]
				]
			],
		];
		yield 'should accept original wikitext in body' => [
			$attribs,
			$html,
			$expectedText, // TODO: ensure it's actually used!
		];

		// should use original html for selser (default) //////////////////////
		$originalDataParsoid = $this->getJsonFromFile( 'MainPage-original.data-parsoid' );
		$attribs = [
			'opts' => [
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						'body' => $this->getTextFromFile( 'MainPage-original.html' ),
					],
					'data-parsoid' => [
						'headers' => [
							'content-type' => $dataParsoidContentType,
						],
						'body' => $originalDataParsoid
					]
				]
			],
		];
		yield 'should use original html for selser (default)' => [
			$attribs,
			$html,
			$expectedText,
		];

		// should use original html for selser (1.1.1, meta) ///////////////////
		$attribs = [
			'opts' => [
				'original' => [
					'html' => [
						'headers' => [
							// XXX: If this is required anyway, how do we know we are using the
							//      version given in the HTML?
							'content-type' => 'text/html; profile="mediawiki.org/specs/html/1.1.1"',
						],
						'body' => $this->getTextFromFile( 'MainPage-data-parsoid-1.1.1.html' ),
					],
					'data-parsoid' => [
						'headers' => [
							'content-type' => $dataParsoidContentType,
						],
						'body' => $originalDataParsoid
					]
				]
			],
		];
		yield 'should use original html for selser (1.1.1, meta)' => [
			$attribs,
			$html,
			$expectedText,
		];

		// should accept original html for selser (1.1.1, headers) ////////////
		$attribs = [
			'opts' => [
				'original' => [
					'html' => [
						'headers' => [
							// Set the schema version to 1.1.1!
							'content-type' => 'text/html; profile="mediawiki.org/specs/html/1.1.1"',
						],
						// No schema version in HTML
						'body' => $this->getTextFromFile( 'MainPage-original.html' ),
					],
					'data-parsoid' => [
						'headers' => [
							'content-type' => $dataParsoidContentType,
						],
						'body' => $originalDataParsoid
					]
				]
			],
		];
		yield 'should use original html for selser (1.1.1, headers)' => [
			$attribs,
			$html,
			$expectedText,
		];

		// Return original wikitext when HTML doesn't change ////////////////////////////
		// New and old html are identical, which should produce no diffs
		// and reuse the original wikitext.
		$html = '<html><body id="mwAA"><div id="mwBB">Selser test</div></body></html>';
		$dataParsoid = [
			'ids' => [
				'mwAA' => [],
				'mwBB' => [ 'autoInsertedEnd' => true, 'stx' => 'html' ]
			]
		];

		$attribs = [
			'oldid' => 1, // Will be replaced by the revision ID of the default test page
			'opts' => [
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						// original HTML is the same as the new HTML
						'body' => $html
					],
					'data-parsoid' => [
						'body' => $dataParsoid,
					]
				]
			],
		];
		yield 'should use selser with supplied wikitext' => [
			$attribs,
			$html,
			[ 'UTContent' ], // Returns original wikitext, because HTML didn't change.
		];

		// Should fall back to non-selective serialization. //////////////////
		// Without the original wikitext, use non-selective serialization.
		$attribs = [
			// No wikitext, no revid/oldid
			'opts' => [
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						// original HTML is the same as the new HTML
						'body' => $html
					],
					'data-parsoid' => [
						'body' => $dataParsoid,
					]
				]
			],
		];
		yield 'Should fallback to non-selective serialization' => [
			$attribs,
			$html,
			[ '<div>Selser test' ],
		];

		// should apply data-parsoid to duplicated ids /////////////////////////
		$html = '<html><body id="mwAA"><div id="mwBB">data-parsoid test</div>' .
			'<div id="mwBB">data-parsoid test</div></body></html>';
		$originalHtml = '<html><body id="mwAA"><div id="mwBB">data-parsoid test</div></body></html>';

		$attribs = [
			'opts' => [
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						'body' => $originalHtml
					],
					'data-parsoid' => [
						'body' => $dataParsoid,
					]
				]
			],
		];
		yield 'should apply data-parsoid to duplicated ids' => [
			$attribs,
			$html,
			[ '<div>data-parsoid test</div><div>data-parsoid test</div>' ],
		];

		// should apply original data-mw ///////////////////////////////////////
		$html = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">hi</p>';
		$originalHtml = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">ho</p>';
		$dataParsoid = [ 'ids' => [ 'mwAQ' => [ 'pi' => [ [ [ 'k' => '1' ] ] ] ] ] ];
		$dataMediaWiki = [
			'ids' => [
				'mwAQ' => [
					'parts' => [ [
						'template' => [
							'target' => [ 'wt' => '1x', 'href' => './Template:1x' ],
							'params' => [ '1' => [ 'wt' => 'hi' ] ],
							'i' => 0
						]
					] ]
				]
			]
		];
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE, // enable data-mw processing
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						'body' => $originalHtml
					],
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [
						'body' => $dataMediaWiki,
					],
				]
			],
		];
		yield 'should apply original data-mw' => [
			$attribs,
			$html,
			[ '{{1x|hi}}' ],
		];

		// should give precedence to inline data-mw over original ////////
		$html = '<p about="#mwt1" typeof="mw:Transclusion" data-mw=\'{"parts":[{"template":{"target":{"wt":"1x","href":"./Template:1x"},"params":{"1":{"wt":"hi"}},"i":0}}]}\' id="mwAQ">hi</p>';
		$originalHtml = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">ho</p>';
		$dataParsoid = [ 'ids' => [ 'mwAQ' => [ 'pi' => [ [ [ 'k' => '1' ] ] ] ] ] ];
		$dataMediaWiki = [ 'ids' => [ 'mwAQ' => [] ] ]; // Missing data-mw.parts!
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE, // enable data-mw processing
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						'body' => $originalHtml
					],
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [
						'body' => $dataMediaWiki,
					],
				]
			],
		];
		yield 'should give precedence to inline data-mw over original' => [
			$attribs,
			$html,
			[ '{{1x|hi}}' ],
		];

		// should not apply original data-mw if modified is supplied ///////////
		$html = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">hi</p>';
		$originalHtml = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">ho</p>';
		$dataParsoid = [ 'ids' => [ 'mwAQ' => [ 'pi' => [ [ [ 'k' => '1' ] ] ] ] ] ];
		$dataMediaWiki = [ 'ids' => [ 'mwAQ' => [] ] ]; // Missing data-mw.parts!
		$dataMediaWikiModified = [
			'ids' => [
				'mwAQ' => [
					'parts' => [ [
						'template' => [
							'target' => [ 'wt' => '1x', 'href' => './Template:1x' ],
							'params' => [ '1' => [ 'wt' => 'hi' ] ],
							'i' => 0
						]
					] ]
				]
			]
		];
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE, // enable data-mw processing
				'data-mw' => [ // modified data
					'body' => $dataMediaWikiModified,
				],
				'original' => [
					'html' => [
						'headers' => $htmlHeaders999,
						'body' => $originalHtml
					],
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [ // original data
						'body' => $dataMediaWiki,
					],
				]
			],
		];
		yield 'should not apply original data-mw if modified is supplied' => [
			$attribs,
			$html,
			[ '{{1x|hi}}' ],
		];

		// should apply original data-mw when modified is absent (captions 1) ///////////
		$html = $this->getTextFromFile( 'Image.html' );
		$dataParsoid = [ 'ids' => [
			'mwAg' => [ 'optList' => [ [ 'ck' => 'caption', 'ak' => 'Testing 123' ] ] ],
			'mwAw' => [ 'a' => [ 'href' => './File:Foobar.jpg' ], 'sa' => [] ],
			'mwBA' => [
				'a' => [ 'resource' => './File:Foobar.jpg', 'height' => '28', 'width' => '240' ],
				'sa' => [ 'resource' => 'File:Foobar.jpg' ]
			]
		] ];
		$dataMediaWiki = [ 'ids' => [ 'mwAg' => [ 'caption' => 'Testing 123' ] ] ];

		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE, // enable data-mw processing
				'original' => [
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [ // original data
						'body' => $dataMediaWiki,
					],
					'html' => [
						'headers' => $htmlHeaders999,
						'body' => $html
					],
				]
			],
		];
		yield 'should apply original data-mw when modified is absent (captions 1)' => [
			$attribs,
			$html, // modified HTML
			[ '[[File:Foobar.jpg|Testing 123]]' ],
		];

		// should give precedence to inline data-mw over modified (captions 2) /////////////
		$htmlModified = $this->getTextFromFile( 'Image-data-mw.html' );
		$dataMediaWikiModified = [
			'ids' => [
				'mwAg' => [ 'caption' => 'Testing 123' ]
			]
		];

		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE, // enable data-mw processing
				'data-mw' => [
					'body' => $dataMediaWikiModified,
				],
				'original' => [
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [ // original data
						'body' => $dataMediaWiki,
					],
					'html' => [
						'headers' => $htmlHeaders999,
						'body' => $html
					],
				]
			],
		];
		yield 'should give precedence to inline data-mw over modified (captions 2)' => [
			$attribs,
			$htmlModified, // modified HTML
			[ '[[File:Foobar.jpg]]' ],
		];

		// should give precedence to modified data-mw over original (captions 3) /////////////
		$dataMediaWikiModified = [
			'ids' => [
				'mwAg' => []
			]
		];

		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE, // enable data-mw processing
				'data-mw' => [
					'body' => $dataMediaWikiModified,
				],
				'original' => [
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [ // original data
						'body' => $dataMediaWiki,
					],
					'html' => [
						'headers' => $htmlHeaders999,
						'body' => $html
					],
				]
			],
		];
		yield 'should give precedence to modified data-mw over original (captions 3)' => [
			$attribs,
			$html, // modified HTML
			[ '[[File:Foobar.jpg]]' ],
		];

		// should apply extra normalizations ///////////////////
		$htmlModified = 'Foo<h2></h2>Bar';
		$attribs = [
			'opts' => [
				'original' => []
			],
		];
		yield 'should apply extra normalizations' => [
			$attribs,
			$htmlModified, // modified HTML
			[ 'FooBar' ], // empty tag was stripped
		];

		// should apply version downgrade ///////////
		$htmlOfMinimal = $this->getTextFromFile( 'Minimal.html' ); // Uses profile version 2.4.0
		$attribs = [
			'opts' => [
				// Downgrades are only for pagebundle
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'html' => [
						'headers' => [
							// Specify newer profile version for original HTML
							'content-type' => 'text/html;profile="https://www.mediawiki.org/wiki/Specs/HTML/999.0.0"'
						],
						// The profile version given inline in the original HTML doesn't matter, it's ignored
						'body' => $htmlOfMinimal,
					],
					'data-parsoid' => [ 'body' => [ 'ids' => [] ] ],
					'data-mw' => [ 'body' => [ 'ids' => [] ] ], // required by version 999.0.0
				]
			],
		];
		yield 'should apply version downgrade' => [
			$attribs,
			$htmlOfMinimal,
			[ '123' ]
		];

		// should not apply version downgrade if versions are the same ///////////
		$htmlOfMinimal = $this->getTextFromFile( 'Minimal.html' ); // Uses profile version 2.4.0
		$attribs = [
			'opts' => [
				// Downgrades are only for pagebundle
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'html' => [
						'headers' => [
							// Specify the exact same version specified inline in Minimal.html 2.4.0
							'content-type' => 'text/html;profile="https://www.mediawiki.org/wiki/Specs/HTML/2.4.0"'
						],
						// The profile version given inline in the original HTML doesn't matter, it's ignored
						'body' => $htmlOfMinimal,
					],
					'data-parsoid' => [ 'body' => [ 'ids' => [] ] ],
				]
			],
		];
		yield 'should not apply version downgrade if versions are the same' => [
			$attribs,
			$htmlOfMinimal,
			[ '123' ]
		];

		// should convert html to json ///////////////////////////////////
		$html = $this->getTextFromFile( 'JsonConfig.html' );
		$expectedText = [
			'{"a":4,"b":3}',
		];

		$attribs = [
			'opts' => [ 'contentmodel' => CONTENT_MODEL_JSON ],
		];
		yield 'should convert html to json' => [
			$attribs,
			$html,
			$expectedText,
			[ 'Content-Type' => $wikiTextContentType ], // TODO: this is a lie, it returns JSON!
		];
	}

	/**
	 * @dataProvider provideHtml2wt
	 *
	 * @param array $attribs
	 * @param string $html
	 * @param string[] $expectedText
	 * @param string[] $expectedHeaders
	 *
	 * @covers \MediaWiki\Rest\Handler\ParsoidHandler::html2wt
	 */
	public function testHtml2wt(
		array $attribs,
		string $html,
		array $expectedText,
		array $expectedHeaders = []
	) {
		$wikitextProfileUri = 'https://www.mediawiki.org/wiki/Specs/wikitext/1.0.0';
		$expectedHeaders += [
			'content-type' => "text/plain; charset=utf-8; profile=\"$wikitextProfileUri\"",
		];

		$page = $this->getExistingTestPage();
		$pageConfig = $this->getPageConfig( $page );

		$attribs += self::DEFAULT_ATTRIBS;
		$attribs['opts'] += self::DEFAULT_ATTRIBS['opts'];
		$attribs['opts']['from'] = $attribs['opts']['from'] ?? 'html';
		$attribs['envOptions'] += self::DEFAULT_ATTRIBS['envOptions'];

		if ( $attribs['oldid'] ) {
			// Set the actual ID of an existing revision
			$attribs['oldid'] = $page->getLatest();
		}

		$handler = $this->newParsoidHandler();

		$response = $handler->html2wt( $pageConfig, $attribs, $html );
		$body = $response->getBody();
		$body->rewind();
		$wikitext = $body->getContents();

		foreach ( $expectedHeaders as $name => $value ) {
			$this->assertSame( $value, $response->getHeaderLine( $name ) );
		}

		foreach ( $expectedText as $exp ) {
			$this->assertStringContainsString( $exp, $wikitext );
		}
	}

	public function provideHtml2wtThrows() {
		$html = '<html lang="en"><body>123</body></html>';

		$profileVersion = '2.4.0';
		$htmlProfileUri = 'https://www.mediawiki.org/wiki/Specs/HTML/' . $profileVersion;
		$htmlContentType = "text/html;profile=\"$htmlProfileUri\"";
		$htmlHeaders = [
			'content-type' => $htmlContentType,
		];

		// XXX: what does version 999.0.0 mean?!
		$htmlContentType999 = 'text/html;profile="https://www.mediawiki.org/wiki/Specs/HTML/999.0.0"';
		$htmlHeaders999 = [
			'content-type' => $htmlContentType999,
		];

		// Content-type of original html is missing ////////////////////////////
		$attribs = [
			'opts' => [
				'original' => [
					'html' => [
						// no headers with content type
						'body' => $html,
					],
				]
			],
		];
		yield 'Content-type of original html is missing' => [
			$attribs,
			$html,
			new HttpException(
				'Content-type of original html is missing.', 400
			)
		];

		// should fail to downgrade the original version for an unknown transition ///////////
		$htmlOfMinimal = $this->getTextFromFile( 'Minimal.html' );
		$htmlOfMinimal2222 = $this->getTextFromFile( 'Minimal-2222.html' );
		$attribs = [
			'opts' => [
				'original' => [
					'html' => [
						'headers' => [
							// Specify version 2222.0.0!
							'content-type' => 'text/html;profile="https://www.mediawiki.org/wiki/Specs/HTML/2222.0.0"'
						],
						'body' => $htmlOfMinimal2222,
					],
					'data-parsoid' => [ 'body' => [ 'ids' => [] ] ],
				]
			],
		];
		yield 'should fail to downgrade the original version for an unknown transition' => [
			$attribs,
			$htmlOfMinimal,
			new HttpException(
				'Modified (2.4.0) and original (2222.0.0) html are of different ' .
				'type, and no path to downgrade.', 400
			)
		];

		// DSR offsetType mismatch: UCS2 vs byte ///////////////////////////////
		$attribs = [
			'envOptions' => [
				'offsetType' => 'byte',
			],
			'opts' => [
				// Enable selser
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'html' => [
						'headers' => $htmlHeaders,
						'body' => $html,
					],
					'data-parsoid' => [
						'body' => [
							'offsetType' => 'UCS2',
							'ids' => [],
						]
					],
				]
			],
		];
		yield 'DSR offsetType mismatch: UCS2 vs byte' => [
			$attribs,
			$html,
			new HttpException(
				'DSR offsetType mismatch: UCS2 vs byte',
				406
			)
		];

		// Could not find previous revision ////////////////////////////
		$attribs = [
			'oldid' => 1155779922,
		];
		yield 'Could not find previous revision' => [
			$attribs,
			$html,
			new HttpException(
				'Could not find previous revision. Has the page been locked / deleted?',
				409
			)
		];

		// should return a 400 for missing inline data-mw (2.x) ///////////////////
		$html = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">hi</p>';
		$dataParsoid = [ 'ids' => [ 'mwAQ' => [ 'pi' => [ [ [ 'k' => '1' ] ] ] ] ] ];
		$htmlOrig = '<p about="#mwt1" typeof="mw:Transclusion" id="mwAQ">ho</p>';
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'html' => [
						'headers' => $htmlHeaders,
						// slightly modified
						'body' => $htmlOrig,
					]
				]
			],
		];
		yield 'should return a 400 for missing inline data-mw (2.x)' => [
			$attribs,
			$html,
			new HttpException(
				'Cannot serialize mw:Transclusion without data-mw.parts or data-parsoid.src',
				400
			)
		];

		// should return a 400 for not supplying data-mw //////////////////////
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'html' => [
						'headers' => $htmlHeaders999,
						'body' => $htmlOrig,
					]
				]
			],
		];
		yield 'should return a 400 for not supplying data-mw' => [
			$attribs,
			$html,
			new HttpException(
				'Invalid data-mw was provided',
				400
			)
		];

		// should return a 400 for missing modified data-mw
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'data-parsoid' => [
						'body' => $dataParsoid,
					],
					'data-mw' => [
						'body' => [
							// Missing data-mw.parts!
							'ids' => [ 'mwAQ' => [] ],
						]
					],
					'html' => [
						'headers' => $htmlHeaders999,
						'body' => $htmlOrig,
					]
				]
			],
		];
		yield 'should return a 400 for missing modified data-mw' => [
			$attribs,
			$html,
			new HttpException(
				'Cannot serialize mw:Transclusion without data-mw.parts or data-parsoid.src',
				400
			)
		];

		// should return http 400 if supplied data-parsoid is empty ////////////
		$html = '<html><head></head><body><p>hi</p></body></html>';
		$htmlOrig = '<html><head></head><body><p>ho</p></body></html>';
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_PAGEBUNDLE,
				'original' => [
					'data-parsoid' => [
						'body' => [],
					],
					'html' => [
						'headers' => $htmlHeaders,
						'body' => $htmlOrig,
					]
				]
			],
		];
		yield 'should return http 400 if supplied data-parsoid is empty' => [
			$attribs,
			$html,
			new HttpException(
				'Invalid data-parsoid was provided.',
				400
			)
		];

		// TODO: ResourceLimitExceededException from $parsoid->dom2wikitext -> 413
		// TODO: ClientError from $parsoid->dom2wikitext -> 413
		// TODO: Errors from PageBundle->validate
	}

	/**
	 * @dataProvider provideHtml2wtThrows
	 *
	 * @param array $attribs
	 * @param string $html
	 * @param Exception $expectedException
	 *
	 * @covers \MediaWiki\Rest\Handler\ParsoidHandler::html2wt
	 */
	public function testHtml2wtThrows(
		array $attribs,
		string $html,
		Exception $expectedException
	) {
		if ( isset( $attribs['oldid'] ) ) {
			// If a specific revision ID is requested, it's almost certain to no exist.
			// So we are testing with a non-existing page.
			$page = $this->getNonexistingTestPage();
		} else {
			$page = $this->getExistingTestPage();
		}

		$pageConfig = $this->getPageConfig( $page );

		$attribs += self::DEFAULT_ATTRIBS;
		$attribs['opts'] += self::DEFAULT_ATTRIBS['opts'];
		$attribs['opts']['from'] = $attribs['opts']['from'] ?? 'html';
		$attribs['envOptions'] += self::DEFAULT_ATTRIBS['envOptions'];

		$handler = $this->newParsoidHandler();

		$this->expectException( get_class( $expectedException ) );
		$this->expectExceptionCode( $expectedException->getCode() );
		$this->expectExceptionMessage( $expectedException->getMessage() );

		$handler->html2wt( $pageConfig, $attribs, $html );
	}

	public function provideDom2wikitextException() {
		yield 'ClientError' => [
			new ClientError( 'test' ),
			new HttpException( 'test', 400 )
		];

		yield 'ResourceLimitExceededException' => [
			new ResourceLimitExceededException( 'test' ),
			new HttpException( 'test', 413 )
		];
	}

	/**
	 * @dataProvider provideDom2wikitextException
	 *
	 * @param Exception $throw
	 * @param Exception $expectedException
	 *
	 * @covers \MediaWiki\Rest\Handler\ParsoidHandler::html2wt
	 */
	public function testHtml2wtHandlesDom2wikitextException(
		Exception $throw,
		Exception $expectedException
	) {
		$html = '<p>hi</p>';
		$page = $this->getExistingTestPage();
		$pageConfig = $this->getPageConfig( $page );
		$attribs = [
			'opts' => [
				'from' => ParsoidFormatHelper::FORMAT_HTML
			]
		] + self::DEFAULT_ATTRIBS;

		$parsoid = $this->createNoOpMock( Parsoid::class, [ 'dom2wikitext' ] );
		$parsoid->method( 'dom2wikitext' )->willThrowException( $throw );

		$handler = $this->newParsoidHandler( [
			'newParsoid' => static function () use ( $parsoid ) {
				return $parsoid;
			}
		] );

		$this->expectException( get_class( $expectedException ) );
		$this->expectExceptionCode( $expectedException->getCode() );
		$this->expectExceptionMessage( $expectedException->getMessage() );

		$handler->html2wt( $pageConfig, $attribs, $html );
	}

	/**
	 * @covers \MediaWiki\Rest\Handler\ParsoidHandler::html2wt
	 */
	public function testHtml2wtHandlesParseHtmlException() {
		$html = '<p>hi</p>';
		$page = $this->getExistingTestPage();
		$pageConfig = $this->getPageConfig( $page );
		$attribs = [
				'opts' => [
					'from' => ParsoidFormatHelper::FORMAT_HTML
				]
			] + self::DEFAULT_ATTRIBS;

		$handler = $this->newParsoidHandler( [
			'parseHTML' => static function () {
				throw new ClientError( 'test' );
			}
		] );

		$expectedException = new HttpException( 'test', 400 );
		$this->expectException( get_class( $expectedException ) );
		$this->expectExceptionCode( $expectedException->getCode() );
		$this->expectExceptionMessage( $expectedException->getMessage() );

		$handler->html2wt( $pageConfig, $attribs, $html );
	}

	public function provideGetRequestAttributes() {
		// TODO: oldid in path
		// TODO: oldid in body
		// TODO: html as string
		// TODO: html with headers
		// TODO: wikitext override (tryToCreatePageConfig)
		// TODO: wikitext loaded (tryToCreatePageConfig)
		// TODO: ...
	}

	/**
	 * @dataProvider provideGetRequestAttributes
	 * @covers \MediaWiki\Rest\Handler\ParsoidHandler::getRequestAttributes
	 */
	public function testGetRequestAttributes() {
		// TODO: also test tryToCreatePageConfig
		$this->fail( 'TBD' );
	}

	public function provideGetRequestAttributesThrows() {
		// TODO: should require html when serializing
		// TODO: should error when revision not found
	}

	/**
	 * @dataProvider provideGetRequestAttributesThrows
	 * @covers \MediaWiki\Rest\Handler\ParsoidHandler::getRequestAttributes
	 */
	public function testGetRequestAttributesThrows() {
		// TODO: also test tryToCreatePageConfig
		$this->fail( 'TBD' );
	}

}
