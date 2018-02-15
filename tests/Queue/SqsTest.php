<?php
namespace Serato\UserProfileSdk\Test\Queue;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

use Aws\Sdk;
use Aws\Result;
use Aws\MockHandler;
use Aws\Credentials\CredentialProvider;
use Serato\UserProfileSdk\Queue\Sqs;
use Serato\UserProfileSdk\Message\AbstractMessage;
use Serato\UserProfileSdk\Test\Queue\TestMessage;
use Ramsey\Uuid\Uuid;

class SqsTest extends PHPUnitTestCase
{
    private $mockHandler;

    public function testSendMessage()
    {
        $mockMessage = $this->getMockForAbstractClass(AbstractMessage::class, [111]);

        $results = [
            ['QueueUrl'  => 'my-queue-url'],
            ['MessageId' => 'TestMessageId1'],
            ['MessageId' => 'TestMessageId2']
        ];
        $queue = new Sqs(
            $this->getMockedAwsSdk($results)->createSqs(['version' => '2012-11-05']),
            'my-queue-name'
        );

        # Test one of the syntax forms
        $this->assertEquals('TestMessageId1', $queue->sendMessage($mockMessage));
        # And the other form
        $this->assertEquals('TestMessageId2', $mockMessage->send($queue));
        // Mock handler stack should be empty
        $this->assertEquals(0, $this->getAwsMockHandlerStackCount());
    }

    /**
     * @group xxx
     */
    public function testSendBatches()
    {
        // Send 25 messages and ensure that the SDK sends the batches correctly

        $results = [
            ['QueueUrl'  => 'my-queue-url'],
            // Results from sending first batch
            [],
            // Results from sending second batch
            [],
            // Results from sending third batch
            []
        ];

        $queue = new Sqs(
            $this->getMockedAwsSdk($results)->createSqs(['version' => '2012-11-05']),
            'my-queue-name'
        );

        $mockMessage = $this->getMockForAbstractClass(AbstractMessage::class, [111]);

        for ($i = 0; $i < 25; $i++) {
            $queue->sendMessageToBatch($mockMessage);
        }

        // Destroy $queue object to send remaining messages
        unset($queue);

        // Mock handler stack should be empty
        $this->assertEquals(0, $this->getAwsMockHandlerStackCount());
    }

    /**
     * @expectedException \Serato\UserProfileSdk\Exception\InvalidMessageBodyException
     */
    public function testCreateMessageWithInvalidMd5()
    {
        Sqs::createMessage([
            'Body'      => 'A message body',
            'MD5OfBody' => md5('A different message body')
        ]);
    }

    public function testCreateMessage()
    {
        $results = [
            ['QueueUrl'  => 'my-queue-url'],
        ];

        $queue = new Sqs(
            $this->getMockedAwsSdk($results)->createSqs(['version' => '2012-11-05']),
            'my-queue-url'
        );

        # We need to construct a valid `Result` array to pass into the
        # Sqs::createMessage method.
        # Easiest way to do this is to create a mock message and use
        # the Sqs::messageToSqsSendParams method.

        $userId = 666;
        $params = ['param1' => 'val1', 'param2' => 22];

        $mockMessage = $this->getMockForAbstractClass(AbstractMessage::class, [666, $params]);

        $sqsSendParams = $queue->messageToSqsSendParams($mockMessage);

        $sqsReceiveResult = [
            'Body'              => $sqsSendParams['MessageBody'],
            'MD5OfBody'         => md5($sqsSendParams['MessageBody']),
            'MessageAttributes' => $sqsSendParams['MessageAttributes']
        ];

        $receivedMockMessage = Sqs::createMessage(
            $sqsReceiveResult,
            [$mockMessage->getType() => get_class($mockMessage)]
        );

        $this->assertEquals($receivedMockMessage->getUserId(), $userId);
        $this->assertEquals($receivedMockMessage->getParams(), $params);
        // Mock handler stack should be empty
        $this->assertEquals(0, $this->getAwsMockHandlerStackCount());
    }

    /**
     * @group aws-integration
     */
    public function testAwsIntegrationTest()
    {
        $userId = 555;
        $scalarMessageValue = 'A scalar value';
        $arrayMessageValue = ['param1' => 'val1', 'param2' => 22];

        $queueName = 'SeratoUserProfile-Events-test-' . Uuid::uuid4()->toString();

        # Credentials come from:
        # - credentials file on dev VMs
        # - .env variables on build VMs

        $sdk = new Sdk([
            'region' => 'us-east-1',
            'version' => '2014-11-01',
            'credentials' => CredentialProvider::memoize(
                CredentialProvider::chain(
                    CredentialProvider::ini(),
                    CredentialProvider::env()
                )
            )
        ]);
        $awsSqs = $sdk->createSqs(['version' => '2012-11-05']);

        $supQueue = new Sqs($awsSqs, $queueName);

        # Send message via `Serato\UserProfileSdk\Queue\Sqs` instance
        $testMessage = TestMessage::create($userId)
                        ->setScalarValue($scalarMessageValue)
                        ->setArrayValue($arrayMessageValue);
        $messageId = $testMessage->send($supQueue);

        # Use the `Aws\Sdk` instance to receive the message
        # (receiving messages is not the responsibility of the `Serato\UserProfileSdk` SDK)
        $result = [];
        $polls = 0;
        # Might need to poll the queue a few times before getting the message
        # But limit to 5 attempts
        while ($polls < 5 && (!isset($result['Messages']) || count($result['Messages']) === 0)) {
            $result = $awsSqs->receiveMessage([
                'WaitTimeSeconds'       => 20,
                'MessageAttributeNames' => ['All'],
                'QueueUrl'              => $supQueue->getQueueUrl()
            ]);
            $polls++;
        }

        $this->assertTrue(isset($result['Messages']) && count($result['Messages']) > 0);

        if (isset($result['Messages']) && count($result['Messages']) > 0) {
            $message = $result['Messages'][0];
            $this->assertEquals($message['MessageId'], $messageId);

            $testMessageReceived = Sqs::createMessage(
                $message,
                [$testMessage->getType() => get_class($testMessage)]
            );

            $this->assertEquals($testMessageReceived->getScalarValue(), $scalarMessageValue);
            $this->assertEquals($testMessageReceived->getArrayValue(), $arrayMessageValue);
        }

        # Delete the queue using the AWS SDK
        $awsSqs->deleteQueue(['QueueUrl' => $supQueue->getQueueUrl()]);
    }

    /**
     * @param array $mockResults    An array of mock results to return from SDK clients
     * @return Sdk
     */
    protected function getMockedAwsSdk(array $mockResults = [])
    {
        $this->mockHandler = new MockHandler();
        foreach ($mockResults as $result) {
            $this->mockHandler->append(new Result($result));
        }
        return new Sdk([
            'region' => 'us-east-1',
            'version' => '2014-11-01',
            'credentials' => [
                'key' => 'my-access-key-id',
                'secret' => 'my-secret-access-key'
            ],
            'handler' => $this->mockHandler
        ]);
    }

    /**
     * Returns the number of remaining items in the AWS mock handler queue.
     *
     * @return int
     */
    protected function getAwsMockHandlerStackCount()
    {
        return $this->mockHandler->count();
    }
}
