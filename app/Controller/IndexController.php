<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Controller;

use App\Chat\Handler\ErrorMessageHandler;
use App\Chat\HandlerInterface;
use App\Chat\Node;
use App\Model\User;
use App\Service\Dao\UserDao;
use App\Service\Obj\UserObj;
use App\Service\UserService;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Di\Annotation\Inject;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\WebSocket;
use Swoole\Websocket\Frame;

class IndexController extends Controller implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    /**
     * @Inject
     * @var ErrorMessageHandler
     */
    protected $errorMessageHandler;

    /**
     * @Inject
     * @var UserDao
     */
    protected $dao;

    /**
     * @Inject
     * @var UserService
     */
    protected $service;

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        if ($obj = $this->service->find($fd)) {
            $this->service->delete($obj);

            if ($user = $this->dao->firstByToken($obj->token)) {
                $user->is_online = User::OFFLINE;
                $user->save();
            }
        }
    }

    public function onMessage(WebSocket\Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);

        $protocal = 'protocal.' . $data['protocal'] ?? '';
        if (! $this->container->has($protocal)) {
            $this->errorMessageHandler->handle($server, $fd, [
                'message' => 'The Protocal is invalid.',
            ]);
            return;
        }

        /** @var HandlerInterface $handler */
        $handler = $this->container->get($protocal);
        $handler->handle($server, $fd, $data);
    }

    public function onOpen(WebSocket\Server $server, Request $request): void
    {
        $token = $this->request->input('token');

        $user = $this->dao->firstByToken($token);
        if (empty($user)) {
            $this->errorMessageHandler->handle($server, $request->fd, [
                'message' => 'The Token is invalid.',
                'close' => true,
            ]);
            return;
        }

        $this->dao->online($token, $user);
        $node = di()->get(Node::class)->getId();

        $this->service->save(new UserObj($token, $request->fd, $node));

        $user = $this->dao->findOnline()->toArray();

        $result = [
            'protocal' => 'user.list',
            'list' => $user,
        ];

        $server->push($request->fd, json_encode($result));
    }
}
